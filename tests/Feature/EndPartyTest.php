<?php

use App\Enums\LoveStatus;
use App\Events\RoomUpdated;
use App\Jobs\BuildSet;
use App\Jobs\ResolveGuest;
use App\Jobs\SteerSet;
use App\Models\Event;
use App\Services\Dj\GuestClusterer;
use App\Services\Dj\GuestResolver;
use App\Services\Dj\RequestSteerer;
use App\Services\Dj\SetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->event = Event::create(['code' => 'END001', 'name' => 'Rooftop', 'host_token' => 'host-secret']);
    $this->maria = $this->event->guests()->create(['name' => 'Maria', 'color' => '#ffffff']);
    foreach (['Ozark', 'Rice', 'Lisbon'] as $text) {
        $this->maria->loves()->create(['raw_text' => $text, 'status' => LoveStatus::Resolved]);
    }
});

test('only the host link can end the party, and the room hears about it', function () {
    Events::fake([RoomUpdated::class]);

    $this->postJson('/e/END001/end', ['key' => 'wrong'])->assertForbidden();
    expect($this->event->fresh()->hasEnded())->toBeFalse();

    $this->postJson('/e/END001/end', ['key' => 'host-secret'])->assertNoContent();

    expect($this->event->fresh()->hasEnded())->toBeTrue();
    Events::assertDispatchedTimes(RoomUpdated::class, 1);
});

test('after the end a guest cannot join, change their loves or ask for anything', function () {
    Queue::fake();
    $this->event->update(['ended_at' => now()]);

    $this->post('/e/END001/guests', ['name' => 'Lena', 'loves' => ['a', 'b', 'c']])->assertRedirect('/e/END001');
    $as = fn () => $this->withCookie('roomtone_guest_END001', (string) $this->maria->id);
    $as()->put('/e/END001/me', ['name' => 'Maria', 'loves' => ['x', 'y', 'z']])->assertRedirect('/e/END001/me');
    $this->postJson('/e/END001/requests', ['key' => 'host-secret', 'text' => 'more Afrobeats'])->assertRedirect();

    expect($this->event->guests()->count())->toBe(1)
        ->and($this->maria->loves()->pluck('raw_text')->all())->toBe(['Ozark', 'Rice', 'Lisbon'])
        ->and($this->event->hostRequests()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('guests and the host screen are told the party ended', function () {
    $this->event->update(['ended_at' => now()]);

    $this->get('/e/END001')->assertInertia(fn (Assert $page) => $page->component('Join')->where('event.ended', true));
    $this->withCookie('roomtone_guest_END001', (string) $this->maria->id)->get('/e/END001/me')
        ->assertInertia(fn (Assert $page) => $page->component('Guest')->where('event.ended', true));
    $this->get('/e/END001/host')->assertInertia(fn (Assert $page) => $page->where('ended', true));
});

test('work already queued when the party ends makes no Qloo or model calls', function () {
    $this->event->update(['ended_at' => now()]);

    $resolver = Mockery::mock(GuestResolver::class);
    $resolver->shouldNotReceive('resolve');
    (new ResolveGuest($this->maria))->handle($resolver);

    $clusterer = Mockery::mock(GuestClusterer::class);
    $clusterer->shouldNotReceive('cluster');
    $builder = Mockery::mock(SetBuilder::class);
    $builder->shouldNotReceive('build');
    (new BuildSet($this->event))->handle($clusterer, $builder);

    $steerer = Mockery::mock(RequestSteerer::class);
    $steerer->shouldNotReceive('steer');
    (new SteerSet($this->event->hostRequests()->create(['text' => 'calmer'])))->handle($steerer);
});
