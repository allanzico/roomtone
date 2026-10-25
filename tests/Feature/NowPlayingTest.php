<?php

use App\Enums\SetItemStatus;
use App\Jobs\BuildSet;
use App\Models\Event;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('when the last video fails, the playing title is cleared and the set is refilled', function () {
    Queue::fake();
    $event = Event::create(['code' => 'LAST01', 'name' => 'Party', 'host_token' => 'secret']);
    $item = $event->setItems()->create([
        'track_id' => Track::create(['artist' => 'Tems', 'title' => 'Free Mind'])->id,
        'position' => 1,
        'status' => SetItemStatus::Playing,
        'reason_text' => 'because Guest loves Amsterdam',
    ]);

    $this->postJson('/e/LAST01/now-playing', ['key' => 'secret', 'item' => null])->assertNoContent();

    expect($item->fresh()->status)->toBe(SetItemStatus::Played);
    $this->get('/e/LAST01/host?key=secret')->assertInertia(fn (Assert $page) => $page->where('nowPlaying', null));
    Queue::assertPushed(BuildSet::class);
});
