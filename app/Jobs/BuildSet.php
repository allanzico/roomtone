<?php

namespace App\Jobs;

use App\Models\Event;
use App\Services\Dj\SetBuilder;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Stage 2 (see SetBuilder). Unique per event until it starts, and dispatched with a delay, so guests resolving
 * close together share one run; a guest resolved while a build runs schedules the next one.
 */
class BuildSet implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const DEBOUNCE_SECONDS = 20;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public Event $event) {}

    public function uniqueId(): string
    {
        return (string) $this->event->id;
    }

    public function handle(SetBuilder $builder): void
    {
        $result = $builder->build($this->event);

        Log::info('DJ set built', ['event' => $this->event->code, ...array_diff_key($result, ['usage' => 1, 'log' => 1]),
            'rejections' => array_count_values(array_filter($result['log'], fn (string $code) => $code !== 'ok'))]);
    }
}
