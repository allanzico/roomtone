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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Stage 2 of the DJ agent: DjAgent fills the upcoming set from the stored pools through add_track (SetValidator),
 * then code checks completeness, re-runs the agent with a summary if needed, and replaces the queued tracks.
 * Tracks already played or playing stay, and their artists are not repeated.
 */
class SetBuilder
{
    /** Re-runs when the agent stops before every guest with pools has a track. */
    public const MAX_NUDGES = 3;

    /** Songs shown per artist; add_track accepts any of the artist's notable songs. */
    private const SONGS_SHOWN = 3;

    public function __construct(private TrackResolver $tracks) {}

    /** About 1.2 tracks per guest with results, between 10 and 40. */
    public static function targetFor(int $guestsWithPools): int
    {
        return max(10, min(40, (int) ceil($guestsWithPools * 1.2)));
    }

    /**
     * @return array{tracks: int, target: int, unserved: list<int>, nudges: int, log: list<string>, usage: TextUsage|null, seconds: float}
     */
    public function build(Event $event): array
    {
        $started = microtime(true);
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
            return ['tracks' => 0, 'target' => 0, 'unserved' => [], 'nudges' => 0, 'log' => [], 'usage' => null, 'seconds' => 0.0];
        }

        $kept = $event->setItems()->with('track')->whereIn('status', [SetItemStatus::Playing, SetItemStatus::Played])->get();

        $validator = new SetValidator(
            guests: $guests,
            loves: $loves,
            typed: array_values($allLoves->map(fn (GuestLove $love) => GuestResolver::clean($love->raw_text))->all()),
            target: self::targetFor($guestsWithPools),
            usedArtists: array_values($kept->map(fn (SetItem $item) => $item->track->artist)->all()),
            playableTitle: $this->playableTitle(...),
        );

        $prompt = $this->prompt($event, $guests, $loves, array_values($kept->map(fn (SetItem $item) => $item->track->artist)->unique()->all()), $validator->target);
        $usage = $this->run($validator, $prompt, $event);

        $nudges = 0;
        while (! $validator->complete() && $nudges < self::MAX_NUDGES) {
            $nudges++;
            $usage = $usage->add($this->run($validator, $prompt."\n\n".$this->progress($validator, $guests), $event));
        }

        $this->write($event, $validator->accepted(), $kept->isNotEmpty() && $kept->contains('status', SetItemStatus::Playing));

        return [
            'tracks' => count($validator->accepted()),
            'target' => $validator->target,
            'unserved' => $validator->unservedIds(),
            'nudges' => $nudges,
            'log' => $validator->log(),
            'usage' => $usage,
            'seconds' => round(microtime(true) - $started, 1),
        ];
    }

    private function run(SetValidator $validator, string $prompt, Event $event): TextUsage
    {
        $response = DjAgent::make(validator: $validator)->prompt($prompt);

        // A response cut off at max tokens silently loses the tool calls in it.
        if ($response->steps->contains(fn (Step $step) => $step->finishReason === FinishReason::Length)) {
            Log::warning('DJ agent response hit the output token limit', ['event' => $event->code]);
        }

        return $response->usage;
    }

    /**
     * The add_track video check: the title with a playable video, the artist's next song, or null. When the
     * YouTube search quota is used up, the track is accepted anyway and the host screen skips it if it has no video.
     *
     * @param  list<string>  $others
     */
    private function playableTitle(string $artist, string $title, array $others): ?string
    {
        try {
            return $this->tracks->resolve($artist, [$title, ...$others])?->title;
        } catch (YouTubeException) {
            return $title;
        }
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
            .($played === [] ? '' : 'Already played tonight, do not repeat: '.implode(', ', $played)."\n")
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
        DB::transaction(function () use ($event, $accepted, $playing) {
            $event->setItems()->where('status', SetItemStatus::Queued)->delete();
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
