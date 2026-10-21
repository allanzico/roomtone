<?php

namespace App\Events;

use App\Models\Event;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something in the room changed on the server (loves read, islands or set rebuilt, a request answered). Screens
 * reload what they show; the payload stays empty so nothing private travels over the public channel.
 */
class RoomUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public string $code) {}

    public static function for(Event $event): void
    {
        event(new self($event->code));
    }

    public function broadcastOn(): Channel
    {
        return new Channel('room.'.$this->code);
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
