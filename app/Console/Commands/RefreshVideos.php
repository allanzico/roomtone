<?php

namespace App\Console\Commands;

use App\Models\Track;
use App\Services\YouTube\YouTubeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * YouTube's developer policies: stored API data must be refreshed or deleted within 30 days. Runs daily. Uses
 * videos.list only (1 unit per 50 tracks), never the 100-a-day search bucket.
 */
#[Signature('roomtone:refresh-videos')]
#[Description('Re-check stored YouTube video ids older than 29 days; forget the ones that no longer play')]
class RefreshVideos extends Command
{
    public const MAX_AGE_DAYS = 29;

    public function handle(YouTubeClient $youtube): int
    {
        $before = now()->subDays(self::MAX_AGE_DAYS);

        // A stored "no video" is data from YouTube too: forget it, so the next resolve searches again.
        $forgotten = Track::query()
            ->whereNull('youtube_video_id')
            ->where('youtube_checked_at', '<', $before)
            ->update(['youtube_checked_at' => null]);

        $kept = 0;
        $dropped = 0;

        Track::query()
            ->whereNotNull('youtube_video_id')
            ->where('youtube_checked_at', '<', $before)
            ->chunkById(50, function (Collection $tracks) use ($youtube, &$kept, &$dropped) {
                $ids = array_values(array_filter($tracks->map(fn (Track $track) => $track->youtube_video_id)->all()));
                $playable = $youtube->playable($ids);

                foreach ($tracks as $track) {
                    $ok = in_array($track->youtube_video_id, $playable, true);
                    $track->forceFill([
                        'youtube_video_id' => $ok ? $track->youtube_video_id : null,
                        'youtube_checked_at' => $ok ? now() : null,
                    ])->save();
                    $ok ? $kept++ : $dropped++;
                }
            });

        $this->info("Kept {$kept}, dropped {$dropped}, forgot {$forgotten} misses.");

        return self::SUCCESS;
    }
}
