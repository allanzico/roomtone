<?php

namespace App\Jobs;

use App\Events\RoomUpdated;
use App\Models\Guest;
use App\Services\Dj\GuestResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stage 1 for one guest (see GuestResolver), then a set rebuild. Rebuilds are unique per event and delayed, so a
 * burst of joins collapses into one stage-2 run.
 */
class ResolveGuest implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Qloo outages and rate limits are retried; a rejected pick is handled inside GuestResolver.
     *
     * @var list<int>
     */
    public array $backoff = [15, 60];

    public function __construct(public Guest $guest) {}

    public function handle(GuestResolver $resolver): void
    {
        $resolver->resolve($this->guest);
        RoomUpdated::for($this->guest->event);

        BuildSet::dispatch($this->guest->event)->delay(now()->addSeconds(BuildSet::DEBOUNCE_SECONDS));
    }
}
