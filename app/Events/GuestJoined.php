<?php

namespace App\Events;

use App\Models\Guest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A guest joined: the host screen drops their point into the room at once. */
class GuestJoined implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Guest $guest) {}

    public function broadcastOn(): Channel
    {
        return new Channel('room.'.$this->guest->event->code);
    }

    /**
     * Same shape as a guest in the host screen's props; islands follow once the DJ has read their loves.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'guest' => [
                'id' => $this->guest->id,
                'name' => $this->guest->name,
                'color' => $this->guest->color,
                'loves' => $this->guest->loves()->pluck('raw_text')->all(),
                'clusterWeights' => [],
            ],
        ];
    }
}
