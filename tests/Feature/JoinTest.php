<?php

use App\Enums\LoveStatus;
use App\Enums\SetItemStatus;
use App\Events\GuestJoined;
use App\Events\RoomUpdated;
use App\Jobs\BuildSet;
use App\Jobs\ResolveGuest;
use App\Jobs\SteerSet;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Track;
use App\Services\Dj\GuestClusterer;
use App\Services\Dj\GuestResolver;
use App\Services\Dj\SetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->event = Event::create(['code' => 'JOIN01', 'name' => 'Rooftop', 'host_token' => 'host-secret']);
});

function joinWith(array $overrides = []): array
{
    return ['name' => 'Maria', 'loves' => ['Ozark', 'pastéis de nata', 'Lisbon'], ...$overrides];
}

test('the join page shows the room by code, in any case', function () {
    $this->get('/e/join01')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Join')->where('event.code', 'JOIN01')->where('event.name', 'Rooftop'));

    $this->get('/e/NOPE00')->assertNotFound();
});

test('joining saves the guest and three trimmed loves, lands their point, starts stage 1 and remembers the phone', function () {
    Queue::fake();
    Events::fake([GuestJoined::class]);

    $response = $this->post('/e/JOIN01/guests', joinWith(['name' => '  Maria ', 'loves' => [' Ozark ', 'pastéis de nata', "Lisbon\n\n"]]));

    $guest = Guest::sole();
    $response->assertRedirect('/e/JOIN01/me')->assertCookie('roomtone_guest_JOIN01', (string) $guest->id);

    expect($guest->name)->toBe('Maria')
        ->and($guest->color)->toBe('#ffffff')
        ->and($guest->loves()->orderBy('id')->pluck('raw_text')->all())->toBe(['Ozark', 'pastéis de nata', 'Lisbon'])
        ->and($guest->loves()->where('status', LoveStatus::Pending)->count())->toBe(3);

    Events::assertDispatched(GuestJoined::class, fn (GuestJoined $e) => $e->guest->is($guest));
    Queue::assertPushed(ResolveGuest::class, fn (ResolveGuest $job) => $job->guest->is($guest));
});

test('joining needs a name and exactly three loves of up to 80 characters', function (array $data, string $error) {
    Queue::fake();

    $this->from('/e/JOIN01')->post('/e/JOIN01/guests', joinWith($data))->assertSessionHasErrors($error);
    expect(Guest::count())->toBe(0);
})->with([
    'no name' => [['name' => '   '], 'name'],
    'long name' => [['name' => str_repeat('a', 41)], 'name'],
    'two loves' => [['loves' => ['Ozark', 'Lisbon']], 'loves'],
    'an empty love' => [['loves' => ['Ozark', '  ', 'Lisbon']], 'loves.1'],
    'a long love' => [['loves' => ['Ozark', str_repeat('x', 81), 'Lisbon']], 'loves.1'],
]);

test('a phone that already joined goes to its own page instead of joining twice', function () {
    Queue::fake();
    $this->post('/e/JOIN01/guests', joinWith());
    $id = (string) Guest::sole()->id;

    $this->withCookie('roomtone_guest_JOIN01', $id)->get('/e/JOIN01')->assertRedirect('/e/JOIN01/me');
    $this->withCookie('roomtone_guest_JOIN01', $id)->post('/e/JOIN01/guests', joinWith(['name' => 'Again']))->assertRedirect('/e/JOIN01/me');

    expect(Guest::count())->toBe(1);
});

test('a guest\'s cookie only counts in their own room', function () {
    $other = Event::create(['code' => 'OTHER1', 'name' => 'x', 'host_token' => 'other-secret']);
    $stranger = $other->guests()->create(['name' => 'Jake', 'color' => '#ffffff']);

    $this->withCookie('roomtone_guest_JOIN01', (string) $stranger->id)->get('/e/JOIN01/me')->assertRedirect('/e/JOIN01');
    $this->get('/e/JOIN01/me')->assertRedirect('/e/JOIN01');
});

test('the guest page shows how the DJ read each love, then their song and how far away it is', function () {
    $maria = $this->event->guests()->create(['name' => 'Maria', 'color' => '#ffffff']);
    $maria->loves()->create(['raw_text' => 'Ozark', 'status' => LoveStatus::Resolved, 'resolved_name' => 'Ozark']);
    $maria->loves()->create(['raw_text' => 'qxzv', 'status' => LoveStatus::Unresolved]);
    $maria->loves()->create(['raw_text' => 'Lisbon']);
    $maria->loves()->create(['raw_text' => 'Rice and beans', 'status' => LoveStatus::Resolved, 'resolved_name' => 'Rio de Janeiro', 'qloo_type' => 'location']);
    $as = fn () => $this->withCookie('roomtone_guest_JOIN01', (string) $maria->id);

    $as()->get('/e/JOIN01/me')->assertInertia(fn (Assert $page) => $page
        ->component('Guest')
        ->where('guest.loves', [
            // "as" only when the DJ read it as something else.
            ['text' => 'Ozark', 'status' => 'resolved', 'as' => null, 'place' => false, 'dish' => false],
            ['text' => 'qxzv', 'status' => 'unresolved', 'as' => null, 'place' => false, 'dish' => false],
            ['text' => 'Lisbon', 'status' => 'pending', 'as' => null, 'place' => false, 'dish' => false],
            ['text' => 'Rice and beans', 'status' => 'resolved', 'as' => 'Rio de Janeiro', 'place' => true, 'dish' => false],
        ])
        ->where('reading', true)
        ->where('song', null));

    foreach (['Playing', 'Other', 'Theirs'] as $i => $title) {
        $this->event->setItems()->create([
            'track_id' => Track::create(['artist' => "A{$i}", 'title' => $title])->id,
            'position' => $i + 1,
            'status' => $i === 0 ? SetItemStatus::Playing : SetItemStatus::Queued,
            'reason_guest_id' => $title === 'Theirs' ? $maria->id : null,
            'reason_text' => $title === 'Theirs' ? 'because Maria loves Ozark' : 'x',
        ]);
    }

    $as()->get('/e/JOIN01/me')->assertInertia(fn (Assert $page) => $page
        ->where('song', ['title' => 'Theirs', 'artist' => 'A2', 'reason' => 'because Maria loves Ozark', 'playing' => false, 'ahead' => 2]));
});

test('a demo copy can be joined like any party', function () {
    Queue::fake();
    $demo = Event::create(['code' => 'DEMOC1', 'name' => 'Lisbon Rooftop', 'host_token' => 'demo-secret', 'demo' => true]);

    $this->post('/e/DEMOC1/guests', joinWith())->assertRedirect('/e/DEMOC1/me');
    expect($demo->guests()->count())->toBe(1);
});

test('the host screen gets the join link and its QR code', function () {
    $this->get('/e/JOIN01/host')->assertInertia(fn (Assert $page) => $page
        ->where('join.url', url('/e/JOIN01'))
        ->where('join.host', parse_url(url('/'), PHP_URL_HOST))
        ->where('join.qr', fn (string $svg) => str_starts_with($svg, '<svg') && str_contains($svg, '</svg>')));
});

test('a guest\'s arrival goes out on the room\'s public channel with what the host screen draws', function () {
    $guest = $this->event->guests()->create(['name' => 'Maria', 'color' => '#ffffff']);
    $guest->loves()->create(['raw_text' => 'Ozark']);
    $joined = new GuestJoined($guest);

    expect($joined->broadcastOn()->name)->toBe('room.JOIN01')
        ->and($joined->broadcastWith())->toBe(['guest' => ['id' => $guest->id, 'name' => 'Maria', 'color' => '#ffffff', 'loves' => ['Ozark'], 'clusterWeights' => []]])
        ->and((new RoomUpdated('JOIN01'))->broadcastWith())->toBe([]);
});

test('stage 1, set builds and host requests tell the room to refresh', function () {
    Events::fake([RoomUpdated::class]);
    Queue::fake();
    $guest = $this->event->guests()->create(['name' => 'Maria', 'color' => '#ffffff']);

    (new ResolveGuest($guest))->handle(app(GuestResolver::class));
    (new BuildSet($this->event))->handle(app(GuestClusterer::class), app(SetBuilder::class));
    (new SteerSet($this->event->hostRequests()->create(['text' => 'x'])))->failed(new RuntimeException('boom'));

    Events::assertDispatchedTimes(RoomUpdated::class, 3);
    Events::assertDispatched(RoomUpdated::class, fn (RoomUpdated $e) => $e->code === 'JOIN01');
});

test('a guest can change their name and loves: only changed loves are read again, and the room hears about it', function () {
    Queue::fake();
    Events::fake([RoomUpdated::class]);
    $maria = $this->event->guests()->create(['name' => 'Maria', 'color' => '#ffffff']);
    $ozark = $maria->loves()->create(['raw_text' => 'Ozark', 'status' => LoveStatus::Resolved, 'resolved_name' => 'Ozark', 'pool' => [['id' => 'a', 'name' => 'A', 'songs' => [], 'rank' => 1]]]);
    $rice = $maria->loves()->create(['raw_text' => 'Rice and beans', 'status' => LoveStatus::Resolved, 'resolved_name' => 'Rio de Janeiro', 'qloo_type' => 'location']);
    $maria->loves()->create(['raw_text' => 'Lisbon', 'status' => LoveStatus::Resolved, 'resolved_name' => 'Lisbon']);

    $this->withCookie('roomtone_guest_JOIN01', (string) $maria->id)
        ->put('/e/JOIN01/me', ['name' => '  Maria P ', 'loves' => ['Ozark', ' Jollof   rice ', 'Lisbon']])
        ->assertRedirect('/e/JOIN01/me');

    expect($maria->fresh()->name)->toBe('Maria P')
        ->and($ozark->fresh()->status)->toBe(LoveStatus::Resolved)
        ->and($ozark->fresh()->pool)->not->toBeNull()
        ->and($rice->fresh()->only(['raw_text', 'resolved_name', 'qloo_type', 'pool']))
        ->toBe(['raw_text' => 'Jollof rice', 'resolved_name' => null, 'qloo_type' => null, 'pool' => null])
        ->and($rice->fresh()->status)->toBe(LoveStatus::Pending);
    Queue::assertPushed(ResolveGuest::class, fn (ResolveGuest $job) => $job->guest->is($maria));
    Events::assertDispatched(RoomUpdated::class);
});

test('changing nothing reads nothing again; a stranger or a bad form changes nothing', function () {
    Queue::fake();
    $maria = $this->event->guests()->create(['name' => 'Maria', 'color' => '#ffffff']);
    foreach (['Ozark', 'Rice', 'Lisbon'] as $text) {
        $maria->loves()->create(['raw_text' => $text, 'status' => LoveStatus::Resolved]);
    }
    // A phone without the room's cookie (first: test cookies stick for later requests).
    $this->put('/e/JOIN01/me', ['name' => 'X', 'loves' => ['a', 'b', 'c']])->assertRedirect('/e/JOIN01');

    $as = fn () => $this->withCookie('roomtone_guest_JOIN01', (string) $maria->id);
    $as()->put('/e/JOIN01/me', ['name' => 'Maria', 'loves' => ['Ozark', 'Rice', 'Lisbon']])->assertRedirect('/e/JOIN01/me');
    Queue::assertNotPushed(ResolveGuest::class);

    $as()->put('/e/JOIN01/me', ['name' => 'Maria', 'loves' => ['only one']])->assertSessionHasErrors('loves');

    expect($maria->loves()->pluck('raw_text')->all())->toBe(['Ozark', 'Rice', 'Lisbon']);
});
