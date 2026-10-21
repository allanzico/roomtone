<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\Dj\DemoParty;
use App\Services\Dj\GuestClusterer;
use App\Services\Dj\PartyRunner;
use App\Services\Dj\SetBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deploy step: build the demo party from live Qloo, Anthropic and YouTube calls into this server's database. Safe
 * to run any time: a build that fails or falls short (for example once the Qloo key is turned off after judging)
 * keeps the existing demo.
 */
#[Signature('roomtone:demo:build')]
#[Description('Build the demo party (event DEMO) from live calls; keeps the existing demo if the build falls short')]
class BuildDemo extends Command
{
    public function handle(PartyRunner $runner, GuestClusterer $clusterer, SetBuilder $builder): int
    {
        foreach (['services.qloo.key' => 'QLOO_API_KEY', 'ai.providers.anthropic.key' => 'AI_PROVIDER_KEY', 'services.youtube.key' => 'YOUTUBE_API_KEY'] as $config => $env) {
            if (! is_string(config($config)) || config($config) === '') {
                $this->warn("{$env} is not set: keeping the existing demo.");

                return self::SUCCESS;
            }
        }

        $file = DemoParty::file();
        $event = null;

        try {
            $event = $runner->create($file, DemoParty::planFrom($file['plan'], CarbonImmutable::now()));
            $this->line("Resolving {$event->guests()->count()} guests…");
            $runner->resolveAll($event);
            $clusterer->cluster($event);
            $this->line('Building the set…');
            $builder->build($event);
        } catch (Throwable $e) {
            $event?->delete();
            $this->error("Build failed ({$e->getMessage()}): keeping the existing demo.");

            return self::FAILURE;
        }

        $ready = DemoParty::readiness($event);
        $this->line(sprintf('Guests with results: %d/%d. Tracks with a video: %d/%d.',
            $ready['guests_with_pools'], $ready['guests'], $ready['tracks_with_video'], $ready['tracks']));

        if (! $ready['ok']) {
            $event->delete();
            $this->error('Below the bar (75% of guests with results, 10+ tracks, 80% with video): keeping the existing demo.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($event) {
            Event::query()->where('code', DemoParty::CODE)->delete();
            $event->update(['code' => DemoParty::CODE]);
        });

        $this->info('Demo party ready: visitors get their own copy from "Try a demo party".');

        return self::SUCCESS;
    }
}
