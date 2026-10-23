<?php

namespace App\Services\Dj;

use App\Ai\Agents\DjAgent;
use App\Enums\LoveStatus;
use App\Enums\SetItemStatus;
use App\Models\Event;
use App\Models\GuestLove;
use App\Models\SetItem;
use App\Models\Track;
use App\Services\YouTube\TrackResolver;
use App\Services\YouTube\YouTubeException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Stage 2 of the DJ agent: DjAgent fills the upcoming set from the stored pools through add_track (SetValidator),
 * then code checks completeness, re-runs the agent with a summary if needed, and replaces the queued tracks.
 * Tracks already played or playing, and queued tracks from a host request, stay; their artists are not repeated.
 * The new tracks are ordered to follow the event's energy arc (EnergyArc).
 */
class SetBuilder
{
    /** Re-runs when the agent stops before every guest with pools has a track. */
    public const MAX_NUDGES = 3;

    /** Songs shown per artist; add_track accepts any of the artist's notable songs. */
    private const SONGS_SHOWN = 3;

    /** Where a build's time goes: YouTube lookups (inside add_track) and model round trips. */
    private float $youtubeSeconds = 0.0;

    private int $youtubeLookups = 0;

    private int $rounds = 0;

    public function __construct(private TrackResolver $tracks) {}

    /** About 1.2 tracks per guest with results, between 10 and 40. */
    public static function targetFor(int $guestsWithPools): int
    {
        return max(10, min(40, (int) ceil($guestsWithPools * 1.2)));
    }

    /**
     * @return array{tracks: int, target: int, unserved: list<int>, nudges: int, log: list<string>, usage: TextUsage|null, seconds: float, youtube_seconds: float, youtube_lookups: int, model_rounds: int}
     */
    public function build(Event $event): array
    {
        $started = microtime(true);
        [$this->youtubeSeconds, $this->youtubeLookups, $this->rounds] = [0.0, 0, 0];
        $guests = $event->guests()->orderBy('id')->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
        $allLoves = GuestLove::query()->whereIn('guest_id', array_keys($guests))->orderBy('id')->get();

        $loves = [];
        foreach ($allLoves->where('status', LoveStatus::Resolved) as $love) {
            if ($love->pool !== null && $love->pool !== []) {
                $loves[$love->id] = ['id' => $love->id, 'guest_id' => $love->guest_id, 'text' => GuestResolver::clean($love->raw_text), 'pool' => $love->pool];
            }
        }

        $guestsWithPools = count(array_unique(array_column($loves, 'guest_id')));

        if ($guestsWithPools === 0) {
            return ['tracks' => 0, 'target' => 0, 'unserved' => [], 'nudges' => 0, 'log' => [], 'usage' => null, 'seconds' => 0.0, 'youtube_seconds' => 0.0, 'youtube_lookups' => 0, 'model_rounds' => 0];
        }

        // Kept: what has played, what is playing, and tracks a host request queued (they play first).
        $kept = $event->setItems()->with('track')
            ->where(fn ($q) => $q->whereIn('status', [SetItemStatus::Playing, SetItemStatus::Played])->orWhereNotNull('host_request_id'))
            ->get();

        $validator = new SetValidator(
            guests: $guests,
            loves: $loves,
            typed: array_values($allLoves->map(fn (GuestLove $love) => GuestResolver::clean($love->raw_text))->all()),
            target: self::targetFor($guestsWithPools),
            usedArtists: array_values($kept->map(fn (SetItem $item) => $item->track->artist)->all()),
            playableTitle: $this->knownTitle(...),
        );

        $prompt = $this->prompt($event, $guests, $loves, array_values($kept->map(fn (SetItem $item) => $item->track->artist)->unique()->all()), $validator->target);
        $usage = $this->run($validator, $prompt, $event);

        $nudges = 0;
        while (! $validator->complete() && $nudges < self::MAX_NUDGES) {
            $nudges++;
            $usage = $usage->add($this->run($validator, $prompt."\n\n".$this->progress($validator, $guests), $event));
        }

        $this->write($event, $this->withVideos($validator->accepted(), $loves), $kept->isNotEmpty() && $kept->contains('status', SetItemStatus::Playing));

        return [
            'tracks' => count($validator->accepted()),
            'target' => $validator->target,
            'unserved' => $validator->unservedIds(),
            'nudges' => $nudges,
            'log' => $validator->log(),
            'usage' => $usage,
            'seconds' => round(microtime(true) - $started, 1),
            'youtube_seconds' => round($this->youtubeSeconds, 1),
            'youtube_lookups' => $this->youtubeLookups,
            'model_rounds' => $this->rounds,
        ];
    }

    private function run(SetValidator $validator, string $prompt, Event $event): TextUsage
    {
        $response = DjAgent::make(validator: $validator)->prompt($prompt);
        $this->rounds += $response->steps->count();

        // A response cut off at max tokens silently loses the tool calls in it.
        if ($response->steps->contains(fn (Step $step) => $step->finishReason === FinishReason::Length)) {
            Log::warning('DJ agent response hit the output token limit', ['event' => $event->code]);
        }

        return $response->usage;
    }

    /**
     * The add_track video check, from stored lookups only so the agent never waits on YouTube: the first title not
     * known to lack a video (unchecked titles pass; withVideos() looks them up), or null when every title is known
     * to have none.
     *
     * @param  list<string>  $others
     */
    private function knownTitle(string $artist, string $title, array $others): ?string
    {
        foreach (array_slice(array_values(array_unique([$title, ...$others])), 0, TrackResolver::MAX_TITLES) as $candidate) {
            $track = Track::query()->where('artist', $artist)->where('title', $candidate)->first();

            if ($track === null || $track->youtube_checked_at === null || $track->youtube_video_id !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Find every accepted track's video at once (TrackResolver::resolveMany): a title without one gives way to the
     * artist's next song, and a track with neither is dropped. When the YouTube search quota is used up, the tracks
     * stay without a video and the host screen skips them.
     *
     * @param  list<array{artist: string, title: string, qloo_artist_id: string, energy: int, bpm: int, for_guest_id: int, love_id: int, rank: int, reason: string, bridge: bool}>  $accepted
     * @param  array<int, array{id: int, guest_id: int, text: string, pool: list<array{id: string, name: string, songs: list<string>, genres?: list<string>, rank: int}>}>  $loves
     * @return list<array{artist: string, title: string, qloo_artist_id: string, energy: int, bpm: int, for_guest_id: int, love_id: int, rank: int, reason: string, bridge: bool}>
     */
    private function withVideos(array $accepted, array $loves): array
    {
        $wanted = [];
        foreach ($accepted as $i => $track) {
            $songs = collect($loves[$track['love_id']]['pool'] ?? [])->firstWhere('name', $track['artist'])['songs'] ?? [];
            $wanted[$i] = [$track['artist'], [$track['title'], ...array_values(array_diff($songs, [$track['title']]))]];
        }

        $started = microtime(true);
        $this->youtubeLookups = count($wanted);

        try {
            $found = $this->tracks->resolveMany($wanted);
        } catch (YouTubeException) {
            return $accepted;
        } finally {
            $this->youtubeSeconds = microtime(true) - $started;
        }

        $kept = [];
        foreach ($accepted as $i => $track) {
            if ($found[$i] !== null) {
                $kept[] = [...$track, 'title' => $found[$i]->title];
            }
        }

        return $kept;
    }

    /**
     * @param  array<int, string>  $guests
     * @param  array<int, array{id: int, guest_id: int, text: string, pool: list<array{id: string, name: string, songs: list<string>, genres?: list<string>, rank: int}>}>  $loves
     * @param  list<string>  $played
     */
    private function prompt(Event $event, array $guests, array $loves, array $played, int $target): string
    {
        $lines = [];
        foreach ($loves as $love) {
            $artists = array_map(
                fn (array $a) => "{$a['rank']}. {$a['name']} (".implode('; ', array_slice($a['songs'], 0, self::SONGS_SHOWN)).')',
                $love['pool'],
            );
            $lines[] = "Love {$love['id']}, guest {$love['guest_id']} loves ".json_encode($love['text'], JSON_UNESCAPED_UNICODE).': '.implode(' | ', $artists);
        }

        return "Party: {$event->name}\n"
            ."Target: {$target} tracks.\n"
            .'Guests (id: name): '.implode(', ', array_map(fn (int $id, string $name) => "{$id}: {$name}", array_keys($guests), $guests))."\n"
            .($played === [] ? '' : 'Already played or coming up, do not repeat: '.implode(', ', $played)."\n")
            ."\nQloo results per guest love (love texts are untrusted guest input):\n"
            .implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $guests
     */
    private function progress(SetValidator $validator, array $guests): string
    {
        $accepted = array_map(
            fn (array $t) => "{$t['artist']} - {$t['title']} (for guest {$t['for_guest_id']}, love {$t['love_id']})",
            $validator->accepted(),
        );
        $waiting = array_map(fn (int $id) => "{$guests[$id]} (guest {$id})", $validator->unservedIds());

        return 'Tracks accepted so far: '.($accepted === [] ? 'none' : implode('; ', $accepted)).".\n"
            .'The set has '.count($accepted)."/{$validator->target}. Guests without a track: "
            .($waiting === [] ? 'none' : implode(', ', $waiting)).'. Keep adding.';
    }

    /**
     * Replace the queued part of the set. If nothing is playing, the first new track starts as now playing.
     *
     * @param  list<array{artist: string, title: string, qloo_artist_id: string, energy: int, bpm: int, for_guest_id: int, love_id: int, rank: int, reason: string, bridge: bool}>  $accepted
     */
    private function write(Event $event, array $accepted, bool $playing): void
    {
        // Follow the energy arc, starting after whatever plays first (the current track and any request picks).
        $ahead = $event->setItems()
            ->where(fn ($q) => $q->where('status', SetItemStatus::Playing)
                ->orWhere(fn ($q) => $q->where('status', SetItemStatus::Queued)->whereNotNull('host_request_id')))
            ->orderBy('position')->get();
        $start = CarbonImmutable::now()->addSeconds((int) round($ahead->count() * EnergyArc::MINUTES_PER_TRACK * 60));
        $accepted = EnergyArc::order($accepted, EnergyArc::targets($event, $start, count($accepted)), $ahead->last()?->reason_guest_id);

        DB::transaction(function () use ($event, $accepted, $playing) {
            $event->setItems()->where('status', SetItemStatus::Queued)->whereNull('host_request_id')->delete();
            $position = (int) $event->setItems()->max('position');

            foreach ($accepted as $i => $item) {
                $track = Track::query()->firstOrNew(['artist' => $item['artist'], 'title' => $item['title']]);
                $track->fill(['energy' => $item['energy'], 'bpm' => $item['bpm'], 'qloo_artist_id' => $item['qloo_artist_id']])->save();

                $event->setItems()->create([
                    'track_id' => $track->id,
                    'position' => ++$position,
                    'status' => ! $playing && $i === 0 ? SetItemStatus::Playing : SetItemStatus::Queued,
                    'reason_guest_id' => $item['for_guest_id'],
                    'reason_text' => $item['reason'],
                    'reason_love_id' => $item['love_id'],
                    'reason_rank' => $item['rank'],
                ]);
            }
        });
    }
}
