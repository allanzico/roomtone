<?php

namespace App\Console\Commands;

use App\Enums\LoveStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestLove;
use App\Services\Dj\GuestClusterer;
use App\Services\Dj\GuestResolver;
use App\Services\Dj\SetBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Runs the DJ agent on a party file: creates the event and guests, resolves every guest (stage 1, in parallel like
 * a room of people joining at once), builds the set (stage 2) and reports time, tokens, cost and Qloo 429s.
 * Live Qloo, Anthropic and YouTube calls: the output holds Qloo data, so keep it out of the repo.
 */
#[Signature('roomtone:party {file : JSON party file, e.g. database/parties/rooftop.json} {--stage1-only : Resolve guests but do not build the set} {--workers=5 : Guests resolved in parallel}')]
#[Description('Run the DJ agent end to end on a party file and report time, tokens and cost')]
class Party extends Command
{
    /** Haiku 5.5 list prices per million tokens (handover section 7, third-party sources, unconfirmed). */
    private const PRICE = ['input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'cache_write' => 0.125];

    public function handle(GuestClusterer $clusterer, SetBuilder $builder): int
    {
        $file = $this->argument('file');
        $path = is_string($file) ? (str_starts_with($file, '/') ? $file : base_path($file)) : '';

        if (! File::exists($path)) {
            $this->error("No party file at {$path}.");

            return self::FAILURE;
        }

        /** @var array{name: string, guests: list<array{name: string, loves: list<string>}>} $party */
        $party = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        $event = $this->createEvent($party);
        $this->info("Event {$event->code}: {$event->name}, ".count($party['guests']).' guests');

        $log = storage_path('logs/laravel.log');
        $before = File::exists($log) ? substr_count(File::get($log), 'Qloo rate limited') : 0;

        $started = microtime(true);
        $usage = $this->stageOne($event, max(1, (int) $this->option('workers')));
        $stageOne = round(microtime(true) - $started, 1);

        $loves = GuestLove::query()->whereIn('guest_id', $event->guests()->pluck('id'))->with('guest')->get();
        $resolved = $loves->where('status', LoveStatus::Resolved)->count();
        $this->line("Stage 1: {$stageOne}s, {$resolved}/{$loves->count()} loves resolved, ".$this->tokens($usage));

        foreach ($loves->where('status', '!=', LoveStatus::Resolved) as $love) {
            $this->line("  unresolved: {$love->guest->name} / ".GuestResolver::clean($love->raw_text));
        }

        $clusterer->cluster($event);
        $this->line('Clusters: '.$event->tasteClusters()->withCount('guests')->orderByDesc('guests_count')->get()
            ->map(fn ($c) => "{$c->label} ({$c->guests_count})")->implode(', '));

        if (! $this->option('stage1-only')) {
            $result = $builder->build($event);
            $usage = $usage->add($result['usage'] ?? new TextUsage);
            $rejections = array_count_values(array_filter($result['log'], fn (string $code) => $code !== 'ok'));
            arsort($rejections);

            $this->line("Stage 2: {$result['seconds']}s, {$result['tracks']}/{$result['target']} tracks, {$result['nudges']} nudges, "
                .($result['usage'] !== null ? $this->tokens($result['usage']) : 'no model call'));
            $this->line('  rejections: '.($rejections === [] ? 'none' : json_encode($rejections)));
            $this->line('  unserved guests with results: '.($result['unserved'] === [] ? 'none' : implode(', ', Guest::whereIn('id', $result['unserved'])->pluck('name')->all())));

            foreach ($event->setItems()->with('track')->orderBy('position')->get() as $item) {
                $this->line(sprintf('  %2d. %s - %s (#%d) %s%s', $item->position, $item->track->artist, $item->track->title,
                    $item->reason_rank, $item->reason_text, $item->track->youtube_video_id === null ? ' [no video]' : ''));
            }
        }

        $after = File::exists($log) ? substr_count(File::get($log), 'Qloo rate limited') : 0;
        $this->info(sprintf('Total: %.1fs, %s, Qloo 429 retries: %d', microtime(true) - $started, $this->tokens($usage), $after - $before));
        $this->line("Host screen: /e/{$event->code}/host (host link with requests: /e/{$event->code}/host?key={$event->host_token})");

        return self::SUCCESS;
    }

    /**
     * @param  array{name: string, guests: list<array{name: string, loves: list<string>}>}  $party
     */
    private function createEvent(array $party): Event
    {
        do {
            $code = Str::upper(Str::random(6));
        } while (Event::where('code', $code)->exists());

        $event = Event::create(['code' => $code, 'name' => $party['name'], 'host_token' => Str::random(40)]);

        foreach ($party['guests'] as $entry) {
            $guest = $event->guests()->create(['name' => $entry['name'], 'color' => '#ffffff']);

            foreach ($entry['loves'] as $love) {
                $guest->loves()->create(['raw_text' => $love]);
            }
        }

        return $event;
    }

    /**
     * Resolve every guest, $workers at a time in separate processes. The Qloo cap is shared through cache locks.
     */
    private function stageOne(Event $event, int $workers): TextUsage
    {
        $ids = $event->guests()->orderBy('id')->pluck('id')->all();
        $slices = array_filter(array_map(fn (int $w) => array_values(array_filter($ids, fn ($id, $i) => $i % $workers === $w, ARRAY_FILTER_USE_BOTH)), range(0, $workers - 1)));

        $tasks = [];
        foreach (array_values($slices) as $slice) {
            $tasks[] = static function () use ($slice): array {
                $total = new TextUsage;
                foreach ($slice as $id) {
                    $total = $total->add(app(GuestResolver::class)->resolve(Guest::query()->whereKey($id)->firstOrFail()));
                }

                return $total->toArray();
            };
        }

        $results = Concurrency::run($tasks, 900);

        return array_reduce($results, fn (TextUsage $carry, array $usage) => $carry->add(TextUsage::fromArray($usage)), new TextUsage);
    }

    private function tokens(TextUsage $usage): string
    {
        $read = $usage->cacheReadInputTokens ?? 0;
        $write = $usage->cacheWriteInputTokens ?? 0;
        $plain = $usage->inputTokens - $read - $write;
        $cost = ($plain * self::PRICE['input'] + $usage->outputTokens * self::PRICE['output']
            + $read * self::PRICE['cache_read'] + $write * self::PRICE['cache_write']) / 1_000_000;

        return sprintf('%dk in (%dk cache read, %dk cache write), %dk out, about $%.3f',
            $usage->inputTokens / 1000, $read / 1000, $write / 1000, $usage->outputTokens / 1000, $cost);
    }
}
