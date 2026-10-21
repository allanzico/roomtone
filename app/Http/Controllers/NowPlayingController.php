<?php

namespace App\Http\Controllers;

use App\Enums\SetItemStatus;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class NowPlayingController extends Controller
{
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

        return response()->noContent();
    }
}
