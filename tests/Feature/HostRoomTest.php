<?php

use App\Enums\SetItemStatus;
use App\Models\Event;
use App\Models\Track;
use Database\Seeders\DemoPartySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DemoPartySeeder::class);
});

test('host room renders the seeded demo party', function () {
    $this->get('/e/DEMO/host')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HostRoom')
            ->where('event.code', 'DEMO')
            ->where('event.name', 'Lisbon Rooftop')
            ->where('event.energy', 3)
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
            ->where('nowPlaying.reasonText', 'because Maria loves Ozark')
            ->has('queue', 9)
            ->where('queue.0.title', 'I Wanna Dance with Somebody (Who Loves Me)'));
});

test('host room does not need a login and ignores code case', function () {
    $this->get('/e/demo/host')->assertOk();
});

test('host room 404s for an unknown event code', function () {
    $this->get('/e/NOPE42/host')->assertNotFound();
});

test('demo party gives every guest at least one song and plays exactly one', function () {
    $event = Event::query()->where('code', 'DEMO')->firstOrFail();

    expect($event->setItems()->where('status', SetItemStatus::Playing)->count())->toBe(1)
        ->and($event->setItems()->count())->toBe(10)
        ->and($event->guests()->whereDoesntHave('clusters')->count())->toBe(0)
        ->and($event->guests()->pluck('id')->diff($event->setItems()->pluck('reason_guest_id')))->toBeEmpty();
});

test('demo party seeder can run twice without duplicating tracks', function () {
    $this->seed(DemoPartySeeder::class);

    expect(Event::query()->where('code', 'DEMO')->count())->toBe(1)
        ->and(Track::query()->count())->toBe(10);
});
