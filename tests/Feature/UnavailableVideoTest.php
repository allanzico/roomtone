<?php

use App\Enums\SetItemStatus;
use App\Models\Event;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->event = Event::create(['code' => 'VIDEO1', 'name' => 'Rooftop', 'host_token' => 'host-secret']);
    $this->track = Track::create(['artist' => 'Tems', 'title' => 'Free Mind', 'youtube_video_id' => 'gone', 'youtube_checked_at' => now()]);
    $this->item = $this->event->setItems()->create([
        'track_id' => $this->track->id, 'position' => 1, 'status' => SetItemStatus::Playing, 'reason_text' => 'because Maria loves Ozark',
    ]);
});

test('an unavailable video is forgotten (but not searched again) and logged', function () {
    Log::spy();

    $this->postJson('/e/VIDEO1/unavailable', ['key' => 'host-secret', 'item' => $this->item->id, 'error' => 150])->assertNoContent();

    expect($this->track->fresh()->youtube_video_id)->toBeNull()
        ->and($this->track->fresh()->youtube_checked_at)->not->toBeNull();
    Log::shouldHaveReceived('warning')->with('Unavailable YouTube video', Mockery::on(
        fn (array $context) => $context['video'] === 'gone' && $context['error'] === 150 && $context['artist'] === 'Tems',
    ));
});

test('only the host link can report a video', function () {
    $this->postJson('/e/VIDEO1/unavailable', ['key' => 'wrong', 'item' => $this->item->id])->assertForbidden();
    $this->postJson('/e/VIDEO1/unavailable', ['key' => 'host-secret', 'item' => 999])->assertNotFound();

    expect($this->track->fresh()->youtube_video_id)->toBe('gone');
});
