<?php

namespace App\Jobs;

use App\Enums\SetItemStatus;
use App\Models\Event;
use App\Services\YouTube\TrackResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Finds a YouTube video for every track in an event's set that has not been looked up yet. A track whose title
 * has no video but whose fallback does is swapped for the fallback track. Tracks with no video stay in the set;
 * the host screen skips them.
 */
class ResolveSetVideos implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<int, list<string>>  $fallbackTitles  Other songs per set item id, in Qloo's order.
     */
    public function __construct(public Event $event, public array $fallbackTitles = []) {}

    public function handle(TrackResolver $resolver): void
    {
        $items = $this->event->setItems()
            ->with('track')
            ->whereIn('status', [SetItemStatus::Playing, SetItemStatus::Queued])
            ->orderBy('position')
            ->get();

        foreach ($items as $item) {
            if ($item->track->youtube_checked_at !== null) {
                continue;
            }

            $track = $resolver->resolve($item->track->artist, [$item->track->title, ...($this->fallbackTitles[$item->id] ?? [])]);

            if ($track !== null && $track->id !== $item->track_id) {
                $item->update(['track_id' => $track->id]);
            }
        }
    }
}
