<?php

use App\Enums\SetItemStatus;
use App\Jobs\BuildSet;
use App\Models\Event;
use App\Models\Track;
use App\Services\YouTube\YouTubeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('a room with only unplayable songs is retried once after the search pause clears', function () {
    Queue::fake();
    $event = Event::create(['code' => 'PAUSED', 'name' => 'Party', 'host_token' => 'secret']);
    $event->setItems()->create([
        'track_id' => Track::create(['artist' => 'A', 'title' => 'Chosen'])->id,
        'position' => 1, 'status' => SetItemStatus::Queued, 'reason_text' => 'for a guest',
    ]);
    $ready = Event::create(['code' => 'READY1', 'name' => 'Ready', 'host_token' => 'ready-secret']);
    $ready->setItems()->create([
        'track_id' => Track::create(['artist' => 'B', 'title' => 'Playable', 'youtube_video_id' => 'video'])->id,
        'position' => 1, 'status' => SetItemStatus::Playing, 'reason_text' => 'for a guest',
    ]);

    Cache::put('youtube:quota-out', true, now()->addHour());
    $this->artisan('roomtone:resume-sets')->assertSuccessful();
    expect(Cache::has(YouTubeClient::RECOVER_SETS))->toBeTrue();
    Queue::assertNotPushed(BuildSet::class);

    Cache::forget('youtube:quota-out');
    $this->artisan('roomtone:resume-sets')->assertSuccessful();
    Queue::assertPushed(BuildSet::class, 1);
    Queue::assertPushed(BuildSet::class, fn (BuildSet $job) => $job->event->is($event));
    expect(Cache::has(YouTubeClient::RECOVER_SETS))->toBeFalse();

    $this->artisan('roomtone:resume-sets')->assertSuccessful();
    Queue::assertPushed(BuildSet::class, 1);
});
