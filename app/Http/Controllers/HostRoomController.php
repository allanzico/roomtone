<?php

namespace App\Http\Controllers;

use App\Enums\SetItemStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestLove;
use App\Models\SetItem;
use App\Models\TasteCluster;
use Inertia\Inertia;
use Inertia\Response;

class HostRoomController extends Controller
{
    /**
     * The host screen: the 3D room that goes up on the TV next to the speaker.
     */
    public function __invoke(string $code): Response
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

        return Inertia::render('HostRoom', [
            'event' => [
                'code' => $event->code,
                'name' => $event->name,
                'occasion' => $event->occasion,
                'energy' => $event->startingEnergy(),
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
            'nowPlaying' => $nowPlaying ? $this->setItem($nowPlaying) : null,
            'queue' => $set->where('status', SetItemStatus::Queued)->map($this->setItem(...))->values(),
        ]);
    }

    /**
     * @return array{id: int, title: string, artist: string, energy: int, reasonGuestId: int|null, reasonText: string}
     */
    private function setItem(SetItem $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->track->title,
            'artist' => $item->track->artist,
            'energy' => $item->track->energy,
            'reasonGuestId' => $item->reason_guest_id,
            'reasonText' => $item->reason_text,
        ];
    }
}
