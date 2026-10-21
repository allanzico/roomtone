<?php

namespace App\Services\Dj;

use Closure;

/**
 * add_track for a host request: one track per guest, each from Qloo's results for that guest's taste filtered by
 * the request. Code writes the reason, as for the main set.
 *
 * @phpstan-type Pooled array{id: string, name: string, songs: list<string>, rank: int}
 * @phpstan-type Accepted array{artist: string, title: string, qloo_artist_id: string, energy: int, bpm: int, for_guest_id: int, rank: int, reason: string}
 */
class RequestValidator
{
    /** @var list<Accepted> */
    private array $accepted = [];

    /** @var list<string> */
    private array $log = [];

    /** @var array<string, true> */
    private array $typed = [];

    /** @var array<string, true> */
    private array $used = [];

    /**
     * @param  array<int, string>  $guests  Every guest, id => name.
     * @param  array<int, list<Pooled>>  $pools  Steered results by guest id.
     * @param  list<string>  $typed  Everything guests typed.
     * @param  list<string>  $usedArtists  Artists already in the set (played, playing or queued).
     * @param  (Closure(string, string, list<string>): ?string)|null  $playableTitle
     */
    public function __construct(
        private array $guests,
        private array $pools,
        array $typed,
        public readonly int $target,
        private string $summary,
        array $usedArtists = [],
        private ?Closure $playableTitle = null,
    ) {
        foreach ($typed as $text) {
            $this->typed[mb_strtolower(trim($text))] = true;
        }

        foreach ($usedArtists as $artist) {
            $this->used[mb_strtolower($artist)] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $args  artist, title, for_guest (id), energy, bpm.
     * @return array<string, mixed>
     */
    public function add(array $args): array
    {
        $artist = $args['artist'] ?? null;
        $title = $args['title'] ?? null;
        $forGuest = $args['for_guest'] ?? null;
        $energy = $args['energy'] ?? null;
        $bpm = $args['bpm'] ?? null;

        if (! is_string($artist) || ! is_string($title) || ! is_int($forGuest) || ! is_int($energy) || ! is_int($bpm)) {
            return $this->reject('bad_args', 'Give artist and title as text, and for_guest, energy and bpm as whole numbers.');
        }

        if (count($this->accepted) >= $this->target) {
            return $this->reject('set_full', 'That is enough tracks. Reply done.');
        }

        $pool = $this->pools[$forGuest] ?? null;

        if ($pool === null) {
            return $this->reject('not_stored', "There are no results for guest {$forGuest}. Use a guest from the list.");
        }

        $match = null;
        foreach ($pool as $entry) {
            if (mb_strtolower($entry['name']) === mb_strtolower(trim($artist))) {
                $match = $entry;
            }
        }

        if ($match === null) {
            return $this->reject('not_in_results', "{$artist} is not in guest {$forGuest}'s results for this request. Their top results: "
                .implode(', ', array_column(array_slice($pool, 0, 5), 'name')).'.');
        }

        $song = null;
        foreach ($match['songs'] as $candidate) {
            if (mb_strtolower($candidate) === mb_strtolower(trim($title))) {
                $song = $candidate;
            }
        }

        if ($song === null) {
            return $this->reject('title_not_notable', "\"{$title}\" is not one of {$match['name']}'s notable songs: "
                .($match['songs'] === [] ? 'none listed, pick another artist' : implode('; ', $match['songs'])).'.');
        }

        if (in_array($forGuest, array_column($this->accepted, 'for_guest_id'), true)) {
            return $this->reject('guest_done', "Guest {$forGuest} already has a track for this request. Pick a different guest.");
        }

        $key = mb_strtolower($match['name']);

        if (isset($this->used[$key]) || in_array($key, array_map('mb_strtolower', array_column($this->accepted, 'artist')), true)) {
            return $this->reject('duplicate_artist', "{$match['name']} is already in the set. Pick a different artist.");
        }

        if (isset($this->typed[$key])) {
            return $this->reject('typed_by_guest', "A guest typed {$match['name']} themselves. Pick a discovery instead.");
        }

        if ($energy < 1 || $energy > 5 || $bpm < 40 || $bpm > 220) {
            return $this->reject('bad_range', 'Energy must be 1 to 5 and bpm 40 to 220.');
        }

        if ($this->playableTitle !== null) {
            $others = array_values(array_filter($match['songs'], fn (string $s) => $s !== $song));
            $playable = ($this->playableTitle)($match['name'], $song, $others);

            if ($playable === null) {
                return $this->reject('no_video', "No playable video was found for {$match['name']}. Pick a different artist.");
            }

            $song = $playable;
        }

        $this->accepted[] = [
            'artist' => $match['name'],
            'title' => $song,
            'qloo_artist_id' => $match['id'],
            'energy' => $energy,
            'bpm' => $bpm,
            'for_guest_id' => $forGuest,
            'rank' => $match['rank'],
            'reason' => "for {$this->guests[$forGuest]}'s taste, as the host asked: {$this->summary}",
        ];
        $this->log[] = 'ok';

        return ['accepted' => true, 'title' => $song, 'tracks_so_far' => count($this->accepted), 'target' => $this->target];
    }

    /**
     * @return list<Accepted>
     */
    public function accepted(): array
    {
        return $this->accepted;
    }

    /**
     * @return list<string>
     */
    public function log(): array
    {
        return $this->log;
    }

    /**
     * @return array{accepted: false, error: string}
     */
    private function reject(string $code, string $message): array
    {
        $this->log[] = $code;

        return ['accepted' => false, 'error' => $message];
    }
}
