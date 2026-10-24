<?php

namespace App\Http\Controllers;

use App\Enums\SetItemStatus;
use App\Jobs\BuildSet;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class NowPlayingController extends Controller
{
    /** Queued tracks left at which the set is refilled. */
    private const REFILL_AT = 2;

    /**
     * The host screen moved on to a track (it ended, or the host skipped). The server keeps the set's state, so set
     * rebuilds and host requests never bring back tracks the room has already heard.
     */
    public function __invoke(Request $request, string $code): Response
    {
        $event = Event::query()->where('code', strtoupper($code))->firstOrFail();

        abort_unless($event->isHostKey($request->input('key')), 403);

        $item = $event->setItems()->whereKey($request->integer('item'))
            ->whereIn('status', [SetItemStatus::Playing, SetItemStatus::Queued])->firstOrFail();

        DB::transaction(function () use ($event, $item) {
            // What was playing, and anything queued before the new track (skipped), counts as played.
            $event->setItems()->whereKeyNot($item->id)
                ->where(fn ($q) => $q->where('status', SetItemStatus::Playing)
                    ->orWhere(fn ($q) => $q->where('status', SetItemStatus::Queued)->where('position', '<', $item->position)))
                ->update(['status' => SetItemStatus::Played]);

            $item->update(['status' => SetItemStatus::Playing]);
        });

        // The set is running out: build more from the guests' stored pools (no Qloo calls), at most every 10 minutes.
        if (! $event->hasEnded()
            && $event->setItems()->where('status', SetItemStatus::Queued)->count() <= self::REFILL_AT
            && Cache::add("refill:{$event->id}", true, now()->addMinutes(10))) {
            BuildSet::dispatch($event);
        }

        return response()->noContent();
    }
}
