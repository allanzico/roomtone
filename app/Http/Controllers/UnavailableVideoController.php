<?php

namespace App\Http\Controllers;

use App\Enums\SetItemStatus;
use App\Models\Event;
use App\Services\YouTube\YouTubeClient;
use App\Services\YouTube\YouTubeException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * The host screen's player could not play a track's video. The screen has already moved on; this asks YouTube
 * whether the video is really gone (removed, private, not embeddable) and only then forgets it, so no set uses it
 * again (the lookup stays stored, so it is not searched again either). A video YouTube still calls playable failed
 * on this screen only (blocked embed, bad network, region): it is kept, or one bad screen would wipe good tracks
 * from every room. When YouTube can't be asked, it is kept too.
 */
class UnavailableVideoController extends Controller
{
    public function __invoke(Request $request, string $code, YouTubeClient $youtube): Response
    {
        $event = Event::query()->where('code', strtoupper($code))->firstOrFail();

        abort_unless($event->isHostKey($request->input('key')), 403);

        $item = $event->setItems()->with('track')->whereKey($request->integer('item'))
            ->whereIn('status', [SetItemStatus::Playing, SetItemStatus::Queued, SetItemStatus::Played])->firstOrFail();
        $track = $item->track;

        $context = [
            'event' => $event->code,
            'artist' => $track->artist,
            'title' => $track->title,
            'video' => $track->youtube_video_id,
            'error' => $request->integer('error'),
        ];

        if ($track->youtube_video_id === null) {
            return response()->noContent();
        }

        try {
            $stillPlayable = $youtube->playable([$track->youtube_video_id]) !== [];
        } catch (YouTubeException) {
            $stillPlayable = true;
        }

        if ($stillPlayable) {
            Log::warning('YouTube video failed on a screen but is playable, kept', $context);

            return response()->noContent();
        }

        Log::warning('Unavailable YouTube video', $context);

        $track->update(['youtube_video_id' => null]);

        return response()->noContent();
    }
}
