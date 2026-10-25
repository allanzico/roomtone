<?php

namespace App\Console\Commands;

use App\Models\Track;
use App\Services\YouTube\TrackResolver;
use App\Services\YouTube\YouTubeClient;
use App\Services\YouTube\YouTubeException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Picks stored before a rule existed (a podcast an hour long, a talent-show audition) are checked again against
 * today's rules from the videos' own data (videos.list, 1 unit per 50 tracks, never the 100-a-day search bucket).
 * A pick that fails is forgotten, and its lookup too, so the track is searched again when there is quota. Runs on
 * every start (docker/start.sh).
 */
#[Signature('roomtone:recheck-videos')]
#[Description('Forget stored YouTube picks that fail the current rules (wrong recording, too long or short)')]
class RecheckVideos extends Command
{
    public function handle(YouTubeClient $youtube, TrackResolver $resolver): int
    {
        $kept = 0;
        $forgotten = 0;

        try {
            Track::query()->whereNotNull('youtube_video_id')->chunkById(50, function (Collection $tracks) use ($youtube, $resolver, &$kept, &$forgotten) {
                $meta = $youtube->inspect(array_values(array_filter($tracks->pluck('youtube_video_id')->all())));

                foreach ($tracks as $track) {
                    $video = $meta[$track->youtube_video_id] ?? null;

                    // Gone from YouTube is refresh-videos' job; here only a pick that is no longer the right one.
                    if ($video === null || $resolver->stillFits($track->artist, $track->title, $video)) {
                        $kept++;

                        continue;
                    }

                    $this->line("Forgot: {$track->artist} - {$track->title} ({$video['title']}, {$video['seconds']}s)");
                    $track->forceFill(['youtube_video_id' => null, 'youtube_checked_at' => null])->save();
                    $forgotten++;
                }
            });
        } catch (YouTubeException $e) {
            // No key, or YouTube down: nothing to recheck now; the next start tries again.
            $this->warn($e->getMessage());

            return self::SUCCESS;
        }

        $this->info("Kept {$kept}, forgot {$forgotten}.");

        return self::SUCCESS;
    }
}
