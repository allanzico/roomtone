<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Http\Requests\StoreHostRequest;
use App\Jobs\SteerSet;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;

class HostRequestController extends Controller
{
    /**
     * The host asks the DJ for something mid-party ("calm it down"). Runs in the background; the host screen polls.
     */
    public function store(StoreHostRequest $request, string $code): RedirectResponse
    {
        $event = Event::query()->where('code', strtoupper($code))->firstOrFail();

        abort_unless($event->isHostKey($request->validated('key')), 403);

        if ($event->hasEnded()) {
            return back()->withErrors(['text' => 'This party has ended.']);
        }

        if ($event->hostRequests()->where('status', RequestStatus::Pending)->where('created_at', '>', now()->subMinutes(5))->exists()) {
            return back()->withErrors(['text' => 'Still working on the last one.']);
        }

        $hostRequest = $event->hostRequests()->create(['text' => trim((string) $request->validated('text'))]);
        SteerSet::dispatch($hostRequest);

        return back();
    }
}
