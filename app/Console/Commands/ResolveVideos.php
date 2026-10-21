<?php

namespace App\Console\Commands;

use App\Jobs\ResolveSetVideos;
use App\Models\Event;
use App\Services\YouTube\YouTubeException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('roomtone:resolve-videos {code : Event code}')]
#[Description('Find YouTube videos for the tracks in an event\'s set (one search per new track, 100 a day)')]
class ResolveVideos extends Command
{
    public function handle(): int
    {
        $code = $this->argument('code');
        $event = is_string($code) ? Event::query()->where('code', strtoupper($code))->first() : null;

        if ($event === null) {
            $this->error('No event with that code.');

            return self::FAILURE;
        }

        try {
            ResolveSetVideos::dispatchSync($event);
        } catch (YouTubeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $items = $event->setItems()->with('track')->orderBy('position')->get();
        $found = $items->filter(fn ($item) => $item->track->youtube_video_id !== null)->count();
        $this->info("{$found} of {$items->count()} tracks have a video.");

        return self::SUCCESS;
    }
}
