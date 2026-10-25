<?php

namespace App\Console\Commands;

use App\Enums\SetItemStatus;
use App\Jobs\BuildSet;
use App\Models\Event;
use App\Services\YouTube\YouTubeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/** Rebuild sets left with only provisional, unplayable songs after YouTube search was paused. */
#[Signature('roomtone:resume-sets')]
#[Description('Retry unplayable active sets once YouTube search is available again')]
class ResumeSets extends Command
{
    public function handle(): int
    {
        if (YouTubeClient::paused()) {
            // Also covers quota pauses already stored before this command was deployed.
            Cache::put(YouTubeClient::RECOVER_SETS, true, now()->addDays(2));

            return self::SUCCESS;
        }

        if (! Cache::has(YouTubeClient::RECOVER_SETS)) {
            return self::SUCCESS;
        }

        $count = 0;
        Event::query()->whereNull('ended_at')->where('created_at', '>=', now()->subDays(2))
            ->whereHas('setItems', fn ($items) => $items->where('status', SetItemStatus::Queued))
            ->whereDoesntHave('setItems', fn ($items) => $items
                ->whereIn('status', [SetItemStatus::Playing, SetItemStatus::Queued])
                ->whereHas('track', fn ($tracks) => $tracks->whereNotNull('youtube_video_id')))
            ->chunkById(100, function (Collection $events) use (&$count) {
                foreach ($events as $event) {
                    BuildSet::dispatch($event);
                    $count++;
                }
            });

        Cache::forget(YouTubeClient::RECOVER_SETS);
        $this->info("Queued {$count} sets for retry.");

        return self::SUCCESS;
    }
}
