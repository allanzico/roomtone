<?php

namespace App\Jobs;

use App\Enums\RequestStatus;
use App\Events\RoomUpdated;
use App\Models\HostRequest;
use App\Services\Dj\RequestSteerer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** A host request (see RequestSteerer). */
class SteerSet implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public HostRequest $request) {}

    public function handle(RequestSteerer $steerer): void
    {
        $steerer->steer($this->request);
        RoomUpdated::for($this->request->event);
    }

    public function failed(?Throwable $e): void
    {
        $this->request->update(['status' => RequestStatus::Failed, 'reply' => 'I lost the thread. Say that again?']);
        RoomUpdated::for($this->request->event);
    }
}
