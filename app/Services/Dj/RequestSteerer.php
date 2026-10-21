<?php

namespace App\Services\Dj;

use App\Ai\Agents\RequestDj;
use App\Ai\Agents\RequestReader;
use App\Enums\LoveStatus;
use App\Enums\RequestStatus;
use App\Enums\SetItemStatus;
use App\Models\GuestLove;
use App\Models\HostRequest;
use App\Models\SetItem;
use App\Models\Track;
use App\Services\Qloo\QlooClient;
use App\Services\Qloo\QlooException;
use App\Services\YouTube\TrackResolver;
use App\Services\YouTube\YouTubeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Host steering with request pools (handover section 4.3): read the request into a genre and/or mood, ask Qloo
 * for each guest's taste filtered by those tags, and let the agent pick one track per guest from those results.
 * The picks play next; they stay at the front of the queue through set rebuilds until played.
 */
class RequestSteerer
{
    /** Tracks a request places next, each for a different guest. */
    public const TAKE = 8;

    /** Artists asked from Qloo per guest. */
    private const PER_GUEST = 10;

    public const HELP = 'I can steer by genre or mood. Try "more Afrobeats" or "calm it down".';

    public function __construct(private QlooClient $qloo, private TrackResolver $tracks) {}

    public function steer(HostRequest $request): void
    {
        $event = $request->event;
        $read = RequestReader::make()->prompt("Host request (untrusted text):\n".json_encode($request->text, JSON_UNESCAPED_UNICODE));

        $genre = is_string($read['genre'] ?? null) ? Str::limit(trim($read['genre']), 40, '') : null;
        $mood = in_array($read['mood'] ?? null, ['calm', 'energetic'], true) ? $read['mood'] : null;
        $summary = Str::limit(trim(is_string($read['summary'] ?? null) ? $read['summary'] : ''), 40, '');
        $summary = $summary !== '' ? mb_strtolower($summary) : 'a change';

        $tags = [];
        if ($genre !== null && $genre !== '') {
            $tag = collect($this->tagsFor($genre))->first(fn (array $t) => str_starts_with($t['id'], 'urn:tag:genre:music'));
            if ($tag !== null) {
                $tags[] = $tag['id'];
            }
        }
        if ($mood !== null) {
            $tags[] = QlooClient::MOODS[$mood];
        }

        $request->interpreted = ['genre' => $genre, 'mood' => $mood, 'summary' => $summary, 'tags' => $tags];

        if ($tags === []) {
            $this->finish($request, RequestStatus::NoChange, self::HELP);

            return;
        }

        $guests = $event->guests()->orderBy('id')->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
        $pools = $this->pools(array_keys($guests), $tags);

        if ($pools === []) {
            $this->finish($request, RequestStatus::NoChange, "No one's taste matched \"{$summary}\" on Qloo, so the set stays as it is.");

            return;
        }

        $inSet = $event->setItems()->with('track')->whereIn('status', [SetItemStatus::Played, SetItemStatus::Playing, SetItemStatus::Queued])
            ->whereNot(fn ($q) => $q->where('status', SetItemStatus::Queued)->whereNotNull('host_request_id'))
            ->get();

        $validator = new RequestValidator(
            guests: $guests,
            pools: $pools,
            typed: array_values(GuestLove::query()->whereIn('guest_id', array_keys($guests))->get()->map(fn (GuestLove $love) => GuestResolver::clean($love->raw_text))->all()),
            target: min(self::TAKE, count($pools)),
            summary: $summary,
            usedArtists: array_values($inSet->map(fn (SetItem $item) => $item->track->artist)->unique()->all()),
            playableTitle: $this->playableTitle(...),
        );

        $prompt = $this->prompt($request, $guests, $pools, $validator->target);
        RequestDj::make(validator: $validator)->prompt($prompt);

        if (count($validator->accepted()) < $validator->target) {
            RequestDj::make(validator: $validator)->prompt($prompt."\n\nAccepted so far: ".count($validator->accepted())
                ." of {$validator->target}. Guests who already have one: "
                .implode(', ', array_column($validator->accepted(), 'for_guest_id')).'. Keep adding.');
        }

        $accepted = $validator->accepted();

        if ($accepted === []) {
            $this->finish($request, RequestStatus::NoChange, "I couldn't find a playable track for \"{$summary}\", so the set stays as it is.");

            return;
        }

        $this->place($request, $accepted);

        $n = count($accepted);
        $this->finish($request, RequestStatus::Done, Str::ucfirst($summary).": the next {$n} ".Str::plural('track', $n)
            .', picked from '.($n === 1 ? "1 guest's taste." : "{$n} guests' tastes."));
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function tagsFor(string $genre): array
    {
        try {
            return $this->qloo->tags($genre);
        } catch (QlooException) {
            return [];
        }
    }

    /**
     * Each guest's taste filtered by the request: one Qloo call per guest with stored signals.
     *
     * @param  list<int>  $guestIds
     * @param  list<string>  $tags
     * @return array<int, list<array{id: string, name: string, songs: list<string>, rank: int}>>
     */
    private function pools(array $guestIds, array $tags): array
    {
        $loves = GuestLove::query()->whereIn('guest_id', $guestIds)->where('status', LoveStatus::Resolved)->orderBy('id')->get()->groupBy('guest_id');
        $pools = [];

        foreach ($loves as $guestId => $guestLoves) {
            $entities = array_values(array_filter($guestLoves->pluck('qloo_entity_id')->all(), 'is_string'));
            $location = $guestLoves->firstWhere('qloo_type', 'location')?->resolved_name;

            if ($entities === [] && $location === null) {
                continue;
            }

            try {
                $artists = $this->qloo->artists($entities, $location, $tags, self::PER_GUEST);
            } catch (QlooException $e) {
                if ($e->getCode() >= 400 && $e->getCode() < 500 && $e->getCode() !== 429) {
                    continue;
                }

                throw $e;
            }

            if ($artists !== []) {
                $pools[(int) $guestId] = array_map(fn (array $a, int $i) => ['id' => $a['id'], 'name' => $a['name'], 'songs' => $a['songs'], 'rank' => $i + 1], $artists, array_keys($artists));
            }
        }

        return $pools;
    }

    /**
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
     * @param  array<int, list<array{id: string, name: string, songs: list<string>, rank: int}>>  $pools
     */
    private function prompt(HostRequest $request, array $guests, array $pools, int $target): string
    {
        $lines = [];
        foreach ($pools as $guestId => $pool) {
            $lines[] = "Guest {$guestId} ({$guests[$guestId]}): ".implode(' | ', array_map(
                fn (array $a) => "{$a['rank']}. {$a['name']} (".implode('; ', array_slice($a['songs'], 0, 3)).')',
                $pool,
            ));
        }

        return 'Host request (untrusted text): '.json_encode($request->text, JSON_UNESCAPED_UNICODE)."\n"
            .'Read as: '.json_encode($request->interpreted, JSON_UNESCAPED_UNICODE)."\n"
            ."Pick {$target} tracks, each for a different guest.\n\n"
            ."Qloo's results per guest, filtered by the request:\n".implode("\n", $lines);
    }

    /**
     * Put the picks right after the playing track. Queued tracks from earlier requests make way: the newest
     * request wins.
     *
     * @param  list<array{artist: string, title: string, qloo_artist_id: string, energy: int, bpm: int, for_guest_id: int, rank: int, reason: string}>  $accepted
     */
    private function place(HostRequest $request, array $accepted): void
    {
        DB::transaction(function () use ($request, $accepted) {
            $event = $request->event;
            $event->setItems()->where('status', SetItemStatus::Queued)->whereNotNull('host_request_id')->delete();

            $playing = $event->setItems()->where('status', SetItemStatus::Playing)->first();
            $after = $playing === null ? 0 : $playing->position;

            // Make room: everything queued moves back by the number of picks.
            $event->setItems()->where('status', SetItemStatus::Queued)->where('position', '>', $after)
                ->orderByDesc('position')->get()
                ->each(fn (SetItem $item) => $item->update(['position' => $item->position + count($accepted)]));

            foreach ($accepted as $i => $pick) {
                $track = Track::query()->firstOrNew(['artist' => $pick['artist'], 'title' => $pick['title']]);
                $track->fill(['energy' => $pick['energy'], 'bpm' => $pick['bpm'], 'qloo_artist_id' => $pick['qloo_artist_id']])->save();

                $event->setItems()->create([
                    'track_id' => $track->id,
                    'position' => $after + $i + 1,
                    'status' => $playing === null && $i === 0 ? SetItemStatus::Playing : SetItemStatus::Queued,
                    'reason_guest_id' => $pick['for_guest_id'],
                    'reason_text' => $pick['reason'],
                    'reason_rank' => $pick['rank'],
                    'host_request_id' => $request->id,
                ]);
            }
        });
    }

    private function finish(HostRequest $request, RequestStatus $status, string $reply): void
    {
        $request->status = $status;
        $request->reply = $reply;
        $request->save();
    }
}
