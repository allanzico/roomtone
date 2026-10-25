<?php

namespace App\Http\Controllers;

use App\Enums\SetItemStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestLove;
use App\Models\HostRequest;
use App\Models\SetItem;
use App\Models\TasteCluster;
use App\Services\YouTube\YouTubeClient;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class HostRoomController extends Controller
{
    /**
     * The host screen: the 3D room that goes up on the TV next to the speaker. With the host key from the host
     * link (?key=…) it also takes requests and keeps the server's set in step with what plays.
     */
    public function __invoke(Request $request, string $code): Response
    {
        $event = Event::query()
            ->where('code', strtoupper($code))
            ->with(['tasteClusters', 'guests.loves', 'guests.clusters'])
            ->firstOrFail();

        $set = $event->setItems()
            ->with('track')
            ->whereIn('status', [SetItemStatus::Playing, SetItemStatus::Queued])
            ->orderBy('position')
            ->get();

        $nowPlaying = $set->firstWhere('status', SetItemStatus::Playing);
        // A track with no playable video never reaches the player's queue (it would sit "playing" with no sound and
        // lock the controls) — unless nothing in the set has a video at all, when the screen only shows the set.
        $queued = $set->where('status', SetItemStatus::Queued);
        $playable = $queued->filter(fn (SetItem $item) => $item->track->youtube_video_id !== null);

        if ($playable->isNotEmpty() || $nowPlaying?->track->youtube_video_id !== null) {
            $queued = $playable;
        }

        $loves = $event->guests->flatMap->loves->keyBy('id');
        $requestGenres = $event->hostRequests()->get()->mapWithKeys(fn (HostRequest $r) => [$r->id => $r->interpreted['genre'] ?? null]);
        $item = fn (SetItem $item) => $this->setItem($item, $this->clusterOf($item, $event, $loves, $requestGenres));
        $key = $request->query('key');
        $isHost = $event->isHostKey($key);
        $latest = $isHost ? $event->hostRequests()->latest('id')->first() : null;

        return Inertia::render('HostRoom', [
            'ended' => $event->hasEnded(),
            // Songs can't be looked up on YouTube right now: new tracks have no video until the quota resets.
            'youtubePaused' => YouTubeClient::paused(),
            'event' => [
                'code' => $event->code,
                'name' => $event->name,
                'occasion' => $event->occasion,
                // The scene's energy follows the arc through the playing track.
                'energy' => $nowPlaying?->track->energy ?? $event->startingEnergy(),
            ],
            'clusters' => $event->tasteClusters->map(fn (TasteCluster $cluster) => [
                'id' => $cluster->id,
                'label' => $cluster->label,
                'color' => $cluster->color,
                'position' => [$cluster->position['x'], $cluster->position['y'], $cluster->position['z']],
            ])->values(),
            'guests' => $event->guests->map(fn (Guest $guest) => [
                'id' => $guest->id,
                'name' => $guest->name,
                'color' => $guest->color,
                'loves' => $guest->loves->map(fn (GuestLove $love) => $love->raw_text)->values(),
                'clusterWeights' => $guest->clusters->map(fn (TasteCluster $cluster) => [
                    'clusterId' => $cluster->id,
                    'weight' => (float) $cluster->getRelationValue('pivot')->weight,
                ])->values(),
            ])->values(),
            'nowPlaying' => $nowPlaying ? $item($nowPlaying) : null,
            'queue' => $queued->map($item)->values(),
            // What guests scan: the join page, as a QR the server draws (no JavaScript QR library).
            'join' => $this->join($event->code),
            // Only for the host link: the key the screen sends back with requests and playback updates.
            'hostKey' => $isHost ? $key : null,
            'latestRequest' => $latest === null ? null : [
                'id' => $latest->id,
                'text' => $latest->text,
                'status' => $latest->status->value,
                'reply' => $latest->reply,
            ],
        ]);
    }

    /**
     * @return array{url: string, host: string, qr: string}
     */
    private function join(string $code): array
    {
        $url = route('events.join', $code);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(240, 0), new SvgImageBackEnd)))->writeString($url);

        return [
            'url' => $url,
            'host' => (string) parse_url($url, PHP_URL_HOST),
            'qr' => trim((string) preg_replace('/^<\?xml[^>]*>/', '', $svg)),
        ];
    }

    /**
     * The island a track belongs to, by its own artist's Qloo genres (kept in the reason love's pool): the room's
     * cluster with a matching label, the reason guest's strongest one first. Null when none matches, so a song is
     * never labelled with a genre just because of who it is for.
     *
     * @param  Collection<int, GuestLove>  $loves
     * @param  Collection<int, string|null>  $requestGenres  The genre each host request asked for, by request id
     */
    private function clusterOf(SetItem $item, Event $event, Collection $loves, Collection $requestGenres): ?int
    {
        // A host request's pick comes from no love's pool, but it is in the genre the host asked for.
        if ($item->host_request_id !== null) {
            $asked = Str::lower((string) $requestGenres->get($item->host_request_id));

            return $asked === '' ? null : $event->tasteClusters->first(function (TasteCluster $c) use ($asked) {
                $label = Str::lower($c->label);

                return $label === $asked || str_contains($label, $asked) || str_contains($asked, $label);
            })?->id;
        }

        // The genres stored on the track when it was picked (any pool: a love, the blend, a calm or energetic list).
        $stored = $item->track->genres;

        if ($stored !== null) {
            $genres = array_map(Str::lower(...), $stored);
            $theirs = ($event->guests->firstWhere('id', $item->reason_guest_id)->clusters ?? collect())
                ->sortByDesc(fn (TasteCluster $c) => (float) $c->getRelationValue('pivot')->weight)->pluck('id')->flip();

            return $event->tasteClusters->filter(fn (TasteCluster $c) => in_array(Str::lower($c->label), $genres, true))
                ->sortBy(fn (TasteCluster $c) => $theirs[$c->id] ?? PHP_INT_MAX)->first()?->id;
        }

        // A blend track has no love row: its pool is its guest's blend.
        $pool = $item->reason_love_id !== null
            ? ($loves->get($item->reason_love_id)->pool ?? [])
            : ($event->guests->firstWhere('id', $item->reason_guest_id)->blend['pool'] ?? []);
        $artist = collect($pool)->first(fn (array $a) => Str::lower($a['name']) === Str::lower($item->track->artist));
        // The reason guest's islands, strongest first.
        $theirs = ($event->guests->firstWhere('id', $item->reason_guest_id)->clusters ?? collect())
            ->sortByDesc(fn (TasteCluster $c) => (float) $c->getRelationValue('pivot')->weight)->values();

        // No genre data for this artist at all (the fake demo, older pools): the guest's own island is the best guess.
        if ($artist === null || ! isset($artist['genres'])) {
            return $theirs->first()?->id;
        }

        $genres = array_map(Str::lower(...), $artist['genres']);
        $matching = $event->tasteClusters->filter(fn (TasteCluster $c) => in_array(Str::lower($c->label), $genres, true));

        $rank = array_flip($theirs->pluck('id')->all());

        return $matching->sortBy(fn (TasteCluster $c) => $rank[$c->id] ?? PHP_INT_MAX)->first()?->id;
    }

    /**
     * @return array{id: int, title: string, artist: string, videoId: string|null, energy: int, reasonGuestId: int|null, reasonText: string, clusterId: int|null}
     */
    private function setItem(SetItem $item, ?int $clusterId): array
    {
        return [
            'id' => $item->id,
            'title' => $item->track->title,
            'artist' => $item->track->artist,
            'videoId' => $item->track->youtube_video_id,
            'energy' => $item->track->energy,
            'reasonGuestId' => $item->reason_guest_id,
            'reasonText' => $item->reason_text,
            'clusterId' => $clusterId,
        ];
    }
}
