<?php

use App\Enums\SetItemStatus;
use App\Models\Event;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RooftopSeeder;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RooftopSeeder::class);
});

test('host room renders the seeded party', function () {
    $this->get('/e/ROOF01/host')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HostRoom')
            ->where('event.code', 'ROOF01')
            ->where('event.name', 'Lisbon Rooftop')
            // The playing track's energy (Last Last, 4), so the scene follows the arc.
            ->where('event.energy', 4)
            ->missing('event.host_token')
            ->has('clusters', 4)
            ->has('clusters.0', fn (Assert $cluster) => $cluster
                ->hasAll(['id', 'label', 'color', 'position'])
                ->has('position', 3))
            ->has('guests', 8)
            ->has('guests.0', fn (Assert $guest) => $guest
                ->where('name', 'Maria')
                ->has('loves', 3)
                ->has('clusterWeights', 2)
                ->etc())
            ->where('nowPlaying.title', 'Last Last')
            ->where('nowPlaying.artist', 'Burna Boy')
            ->where('nowPlaying.videoId', null)
            ->where('nowPlaying.reasonText', 'because Maria loves Ozark')
            ->has('queue', 9)
            ->where('queue.0.title', 'I Wanna Dance with Somebody (Who Loves Me)'));
});

test('host room does not need a login and ignores code case', function () {
    $this->get('/e/roof01/host')->assertOk();
});

test('host room 404s for an unknown event code', function () {
    $this->get('/e/NOPE42/host')->assertNotFound();
});

test('seeded party gives every guest at least one song and plays exactly one', function () {
    $event = Event::query()->where('code', 'ROOF01')->firstOrFail();

    expect($event->setItems()->where('status', SetItemStatus::Playing)->count())->toBe(1)
        ->and($event->setItems()->count())->toBe(10)
        ->and($event->guests()->whereDoesntHave('clusters')->count())->toBe(0)
        ->and($event->guests()->pluck('id')->diff($event->setItems()->pluck('reason_guest_id')))->toBeEmpty();
});

test('the seeder can run twice without duplicating tracks', function () {
    $this->seed(RooftopSeeder::class);

    expect(Event::query()->where('code', 'ROOF01')->count())->toBe(1)
        ->and(Track::query()->count())->toBe(10);
});

test('host room sends each track\'s video id', function () {
    Track::query()->where('title', 'Last Last')->update(['youtube_video_id' => 'abc123']);

    $this->get('/e/ROOF01/host')->assertInertia(fn (Assert $page) => $page->where('nowPlaying.videoId', 'abc123'));
});

test('a track takes the island of its own artist\'s genres, not of the guest it is for', function () {
    $event = Event::where('code', 'ROOF01')->firstOrFail();
    $playing = $event->setItems()->with('track')->where('status', SetItemStatus::Playing)->firstOrFail();
    $guest = $event->guests()->findOrFail($playing->reason_guest_id);
    $fado = $event->tasteClusters()->where('label', 'Fado')->firstOrFail();
    $love = $guest->loves()->firstOrFail();
    $playing->update(['reason_love_id' => $love->id]);

    $pool = fn (array $genres) => [['id' => 'q1', 'name' => $playing->track->artist, 'songs' => [], 'genres' => $genres, 'rank' => 1]];

    $love->update(['pool' => $pool(['Fado', 'World'])]);
    $this->get('/e/ROOF01/host')->assertInertia(fn (Assert $page) => $page->where('nowPlaying.clusterId', $fado->id));

    // No island matches the artist's genres: no genre at all, whatever the guest's island is.
    $love->update(['pool' => $pool(['Chamber Pop', 'Indie Folk'])]);
    $this->get('/e/ROOF01/host')->assertInertia(fn (Assert $page) => $page->where('nowPlaying.clusterId', null));
});

test('a track with no genre data falls back to its guest\'s island', function () {
    $this->get('/e/ROOF01/host')->assertInertia(fn (Assert $page) => $page->whereNot('nowPlaying.clusterId', null));
});

test('a host request\'s pick takes the island of the genre asked for, never its guest\'s island', function () {
    $event = Event::query()->where('code', 'ROOF01')->first();
    $item = $event->setItems()->where('status', SetItemStatus::Playing)->first();
    $house = $event->tasteClusters()->first();
    $request = $event->hostRequests()->create(['text' => 'more house', 'interpreted' => ['genre' => strtolower($house->label), 'mood' => null, 'summary' => 'house', 'tags' => []]]);
    $item->update(['host_request_id' => $request->id, 'reason_love_id' => null]);

    $this->get('/e/ROOF01/host')->assertInertia(fn (Assert $page) => $page->where('nowPlaying.clusterId', $house->id));

    // No island carries that genre: no genre (grey), not the guest's island.
    $request->update(['interpreted' => ['genre' => 'zzz', 'mood' => null, 'summary' => 'zzz', 'tags' => []]]);
    $this->get('/e/ROOF01/host')->assertInertia(fn (Assert $page) => $page->where('nowPlaying.clusterId', null));
});

test('the host screen is told when YouTube lookups are paused', function () {
    $this->get('/e/ROOF01/host')->assertInertia(fn (Assert $page) => $page->where('youtubePaused', false));

    Cache::put('youtube:quota-out', true, 60);
    $this->get('/e/ROOF01/host')->assertInertia(fn (Assert $page) => $page->where('youtubePaused', true));
});
