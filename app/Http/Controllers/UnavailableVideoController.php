<?php

namespace App\Http\Controllers;

use App\Enums\SetItemStatus;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * The host screen's player could not play a track's video (removed, private, or not embeddable). The screen has
 * already moved on; this forgets the video so no set uses it again (the lookup stays stored, so it is not searched
 * again either), and logs it.
 */
class UnavailableVideoController extends Controller
{
    public function __invoke(Request $request, string $code): Response
    {
        $event = Event::query()->where('code', strtoupper($code))->firstOrFail();

        abort_unless($event->isHostKey($request->input('key')), 403);

        $item = $event->setItems()->with('track')->whereKey($request->integer('item'))
            ->whereIn('status', [SetItemStatus::Playing, SetItemStatus::Queued, SetItemStatus::Played])->firstOrFail();
        $track = $item->track;

        Log::warning('Unavailable YouTube video', [
            'event' => $event->code,
            'artist' => $track->artist,
            'title' => $track->title,
            'video' => $track->youtube_video_id,
            'error' => $request->integer('error'),
        ]);

        $track->update(['youtube_video_id' => null]);

        return response()->noContent();
    }
}
