<?php

namespace App\Services\YouTube;

use App\Models\Track;
use Illuminate\Support\Str;

/**
 * Finds a YouTube video for an artist's song, falling back to the artist's next listed song when Qloo names a
 * title with no video (it happens: 1 in 61 spike picks). Every lookup is stored on the track, found or not, so a
 * track costs at most one search ever.
 */
class TrackResolver
{
    /** Titles tried per artist (the pick, then the next listed song) before giving up: each costs a search (100 a day). */
    public const MAX_TITLES = 2;

    /** Words that mark a different recording, unless the song title itself has them. */
    private const WRONG_VERSION = ['live', 'cover', 'remix', 'karaoke', 'sped up', 'slowed', 'reverb', 'nightcore', '8d',
        'instrumental', 'mashup', 'reaction', 'tutorial', 'lesson', 'full album', 'playlist', 'medley', 'acoustic'];

    public function __construct(private YouTubeClient $youtube) {}

    /**
     * The first of the titles that has a playable video, as a track with youtube_video_id set, or null if none does.
     *
     * @param  list<string>  $titles  The picked title first, then the artist's other notable songs.
     */
    public function resolve(string $artist, array $titles): ?Track
    {
        foreach (array_slice(array_values(array_unique($titles)), 0, self::MAX_TITLES) as $title) {
            $track = Track::query()->firstOrNew(['artist' => $artist, 'title' => $title]);

            if ($track->youtube_video_id === null && $track->youtube_checked_at === null) {
                $track->youtube_video_id = $this->find($artist, $title);
                $track->youtube_checked_at = now();
                $track->save();
            }

            if ($track->youtube_video_id !== null) {
                return $track;
            }
        }

        return null;
    }

    /**
     * Many tracks at once, for a whole set: each round searches every track still without a video side by side,
     * then checks all candidates in one videos.list call; the second round tries each artist's next song. Same
     * matching and storage as resolve(). Per key: the track (its video may be null when YouTube could not be asked),
     * or null when none of its titles has a video.
     *
     * @param  array<int, array{0: string, 1: list<string>}>  $wanted  key => [artist, titles, picked title first]
     * @return array<int, Track|null>
     */
    public function resolveMany(array $wanted): array
    {
        $titles = array_map(fn (array $w) => array_slice(array_values(array_unique($w[1])), 0, self::MAX_TITLES), $wanted);
        $found = [];

        for ($round = 0; $round < self::MAX_TITLES; $round++) {
            $search = [];
            foreach ($wanted as $key => [$artist]) {
                $title = $titles[$key][$round] ?? null;

                if (isset($found[$key]) || $title === null) {
                    continue;
                }

                $track = Track::query()->firstOrNew(['artist' => $artist, 'title' => $title]);

                if ($track->youtube_video_id !== null) {
                    $found[$key] = $track;
                } elseif ($track->youtube_checked_at === null) {
                    $search[$key] = $track;
                }
            }

            if ($search === []) {
                continue;
            }

            $results = $this->youtube->searchMany(array_map(fn (Track $t) => "{$t->artist} {$t->title}", $search));
            $ranked = [];
            foreach ($search as $key => $track) {
                $ranked[$key] = $results[$key] === null ? null : $this->ranked($results[$key], $track->artist, $track->title);
            }

            $playable = $this->youtube->playable(array_merge(...array_values(array_filter($ranked, 'is_array'))));

            foreach ($search as $key => $track) {
                // Could not search: not stored, so it is tried again later; kept for now without a video.
                if ($ranked[$key] === null) {
                    $found[$key] = $track;

                    continue;
                }

                $track->youtube_video_id = array_values(array_intersect($ranked[$key], $playable))[0] ?? null;
                $track->youtube_checked_at = now();
                $track->save();

                if ($track->youtube_video_id !== null) {
                    $found[$key] = $track;
                }
            }
        }

        return array_map(fn (int $key) => $found[$key] ?? null, array_combine(array_keys($wanted), array_keys($wanted)));
    }

    private function find(string $artist, string $title): ?string
    {
        return $this->youtube->playable($this->ranked($this->youtube->search("{$artist} {$title}"), $artist, $title))[0] ?? null;
    }

    /**
     * The ids of the videos that are this song, the artist's own uploads first ("- Topic" auto channels, VEVO, the
     * artist's channel), then the rest in YouTube's order.
     *
     * @param  list<array{id: string, title: string, channel: string}>  $videos
     * @return list<string>
     */
    private function ranked(array $videos, string $artist, string $title): array
    {
        $candidates = array_values(array_filter($videos, fn (array $video) => $this->isTheSong($video, $artist, $title)));
        usort($candidates, fn (array $a, array $b) => $this->isOfficial($b, $artist) <=> $this->isOfficial($a, $artist));

        return array_column($candidates, 'id');
    }

    /**
     * @param  array{id: string, title: string, channel: string}  $video
     */
    private function isTheSong(array $video, string $artist, string $title): bool
    {
        $videoTitle = $this->plain($video['title']);
        $song = $this->plain((string) preg_replace('/\s*[(\[].*?[)\]]/', '', $title));

        if ($song === '' || ! $this->contains($videoTitle, $song)) {
            return false;
        }

        // The artist's own channel, or an upload named "Artist - Song". The name anywhere in the title is not enough:
        // "Awen - Mystic Waters" otherwise matches a podcast episode called "... Awen of Water".
        $name = $this->plain($artist);
        if (! str_contains($this->plain($video['channel']), $name) && ! str_starts_with($videoTitle, $name)) {
            return false;
        }

        $asked = $this->plain($title.' '.$artist);
        foreach (self::WRONG_VERSION as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/', $videoTitle) && ! str_contains($asked, $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{id: string, title: string, channel: string}  $video
     */
    private function isOfficial(array $video, string $artist): bool
    {
        $channel = $this->plain($video['channel']);

        return str_ends_with($channel, ' topic') || str_contains($channel, 'vevo') || $channel === $this->plain($artist);
    }

    /**
     * Whether every word of $words is in $text, allowing about one wrong letter in five per word, because Qloo and
     * YouTube transliterate differently ("180 Daraga" is uploaded as "180 Darga").
     */
    private function contains(string $text, string $words): bool
    {
        if (str_contains($text, $words)) {
            return true;
        }

        $have = explode(' ', $text);

        foreach (explode(' ', $words) as $word) {
            $slack = intdiv(strlen($word), 5);
            $found = false;

            foreach ($have as $candidate) {
                if ($candidate === $word || ($slack > 0 && levenshtein($candidate, $word) <= $slack)) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lower case, no accents, punctuation as single spaces: "Beyoncé - Halo" becomes "beyonce halo". Scripts that
     * have no ASCII form (Japanese, Arabic) are kept as typed, lower-cased.
     */
    private function plain(string $text): string
    {
        $ascii = trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))));

        return $ascii !== '' ? $ascii : trim(Str::lower($text));
    }
}
