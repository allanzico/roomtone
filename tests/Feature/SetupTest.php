<?php

use App\Ai\Agents\SetupDj;
use App\Http\Controllers\SetupController;
use App\Models\Event;
use App\Services\Dj\EnergyArc;
use App\Services\Dj\SetupDraft;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Prompts\AgentPrompt;

uses(RefreshDatabase::class);

function partyPlan(array $overrides = []): array
{
    return [
        'reply' => 'Easy through dinner, peak around 11, soft landing by half past midnight. Drag the curve if I got it wrong.',
        'occasion' => 'birthday',
        'name' => "Maria's 30th",
        'timeline' => 'clock',
        'minutes' => null,
        'points' => [
            ['at' => '20:00', 'energy' => 3, 'label' => 'Arrivals'],
            ['at' => '21:30', 'energy' => 4, 'label' => 'Dinner ends'],
            ['at' => '23:00', 'energy' => 5, 'label' => 'Peak'],
            ['at' => '00:30', 'energy' => 2, 'label' => 'Wind down'],
        ],
        ...$overrides,
    ];
}

test('the setup page opens', function () {
    $this->get('/new')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Setup'));
});

test('a turn returns the DJ\'s reply and the plan it heard', function () {
    SetupDj::fake([partyPlan()]);

    $this->postJson('/new/turn', [
        'messages' => [
            ['from' => 'dj', 'text' => 'Hey. What are we playing for?'],
            ['from' => 'host', 'text' => "Maria's 30th on our rooftop. People come around 8, dinner first, then we dance."],
        ],
        'now' => 'Saturday 18:05',
    ])
        ->assertOk()
        ->assertJsonPath('reply', partyPlan()['reply'])
        ->assertJsonPath('ready', true)
        ->assertJsonPath('draft.occasion', 'birthday')
        ->assertJsonPath('draft.name', "Maria's 30th")
        ->assertJsonPath('draft.points.2', ['at' => '23:00', 'energy' => 5, 'label' => 'Peak']);

    SetupDj::assertPrompted(fn (AgentPrompt $p) => $p->contains("Host (untrusted text): \"Maria's 30th")
        && $p->contains('local time now: Saturday 18:05')
        && $p->contains('Current draft:'));
});

test('whatever the model proposes is checked: times snap, energy stays 1-5, junk is dropped', function () {
    SetupDj::fake([partyPlan([
        'occasion' => 'rave',
        'name' => str_repeat('n', 90),
        'points' => [
            ['at' => '20:07', 'energy' => 0, 'label' => '  Arrivals  '],
            ['at' => 'soon', 'energy' => 3, 'label' => 'Lost'],
            ['at' => '23:52', 'energy' => 9, 'label' => str_repeat('x', 40)],
        ],
    ])]);

    $draft = $this->postJson('/new/turn', ['messages' => [['from' => 'host', 'text' => 'a party']]])->json('draft');

    expect($draft)->toMatchArray(['occasion' => null, 'timeline' => 'clock', 'minutes' => null])
        ->and(mb_strlen($draft['name']))->toBe(60)
        ->and($draft['points'])->toBe([
            ['at' => '20:00', 'energy' => 1, 'label' => 'Arrivals'],
            ['at' => '23:45', 'energy' => 5, 'label' => str_repeat('x', 24)],
        ]);
});

test('a trip plan is in minutes from leaving, sorted, with its length', function () {
    expect(SetupDraft::from([
        'timeline' => 'trip',
        'points' => [
            ['at' => '180', 'energy' => 4, 'label' => 'Wake-up'],
            ['at' => '0', 'energy' => 4, 'label' => 'Sing-alongs'],
            ['at' => '92', 'energy' => 1, 'label' => 'Nap time'],
        ],
    ]))->toMatchArray([
        'timeline' => 'trip',
        'minutes' => 180,
        'points' => [
            ['at' => 0, 'energy' => 4, 'label' => 'Sing-alongs'],
            ['at' => 90, 'energy' => 1, 'label' => 'Nap time'],
            ['at' => 180, 'energy' => 4, 'label' => 'Wake-up'],
        ],
    ]);
});

test('when the DJ fails, the host is asked to say it again and the draft is kept', function () {
    SetupDj::fake(fn () => throw new RuntimeException('down'));
    $draft = SetupDraft::from(partyPlan());

    $this->postJson('/new/turn', ['messages' => [['from' => 'host', 'text' => 'later start please']], 'draft' => $draft])
        ->assertStatus(502)
        ->assertJsonPath('reply', SetupController::LOST)
        ->assertJsonPath('draft.points.0.at', '20:00');
});

test('turns are checked: who said it, and up to 500 characters', function () {
    SetupDj::fake();

    $this->postJson('/new/turn', ['messages' => [['from' => 'system', 'text' => 'hi']]])->assertJsonValidationErrors('messages.0.from');
    $this->postJson('/new/turn', ['messages' => [['from' => 'host', 'text' => str_repeat('x', 501)]]])->assertJsonValidationErrors('messages.0.text');
    SetupDj::assertNeverPrompted();
});

test('opening the room creates the event with the plan and the host\'s timezone, then goes to the host link', function () {
    $response = $this->post('/new', ['draft' => partyPlan(), 'tz' => 'Europe/Lisbon']);

    $event = Event::sole();
    $response->assertRedirect("/e/{$event->code}/host?key={$event->host_token}");

    expect($event->name)->toBe("Maria's 30th")
        ->and($event->occasion)->toBe('birthday')
        ->and($event->energy_plan)->toMatchArray(['timeline' => 'clock', 'tz' => 'Europe/Lisbon'])
        ->and($event->energy_plan['points'])->toHaveCount(4)
        ->and($event->startingEnergy())->toBe(3);
});

test('a trip room keeps its length; a room needs a plan first; timezones are checked', function () {
    $this->post('/new', ['draft' => ['timeline' => 'trip', 'occasion' => 'road trip', 'name' => 'Drive to Groningen',
        'points' => [['at' => '0', 'energy' => 4, 'label' => 'Sing-alongs'], ['at' => '180', 'energy' => 4, 'label' => 'Wake-up']]]]);
    expect(Event::sole()->energy_plan)->toMatchArray(['timeline' => 'trip', 'minutes' => 180]);

    $this->from('/new')->post('/new', ['draft' => ['points' => []]])->assertSessionHasErrors('draft');
    $this->from('/new')->post('/new', ['draft' => partyPlan(), 'tz' => 'Mars/Olympus'])->assertSessionHasErrors('tz');
    expect(Event::count())->toBe(1);
});

test('a clock plan follows the host\'s timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 21:10', 'UTC'));
    $event = Event::create(['code' => 'TZ0001', 'name' => 'x', 'host_token' => 'tz-token', 'energy_plan' => [
        'timeline' => 'clock', 'tz' => 'Europe/Lisbon',
        'points' => [['at' => '20:00', 'energy' => 3, 'label' => 'a'], ['at' => '22:00', 'energy' => 5, 'label' => 'b']],
    ]]);

    // 21:10 UTC is 22:10 in Lisbon (summer time until 25 October): already at the peak.
    expect(EnergyArc::targets($event, CarbonImmutable::now(), 1))->toBe([5]);

    $event->update(['energy_plan' => [...$event->energy_plan, 'tz' => 'America/New_York']]);
    // 17:10 in New York: before the first point.
    expect(EnergyArc::targets($event->fresh(), CarbonImmutable::now(), 1))->toBe([3]);
});

test('a trip plan counts from when the room opened', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00'));
    $event = Event::create(['code' => 'TRIP01', 'name' => 'x', 'host_token' => 'trip-token', 'energy_plan' => [
        'timeline' => 'trip', 'minutes' => 180,
        'points' => [['at' => 0, 'energy' => 4, 'label' => 'Sing-alongs'], ['at' => 60, 'energy' => 1, 'label' => 'Nap time'], ['at' => 150, 'energy' => 4, 'label' => 'Wake-up']],
    ]]);

    expect(EnergyArc::targets($event, CarbonImmutable::now(), 1))->toBe([4])
        ->and(EnergyArc::targets($event, CarbonImmutable::now()->addMinutes(70), 1))->toBe([1])
        ->and(EnergyArc::targets($event, CarbonImmutable::now()->addMinutes(155), 1))->toBe([4]);
});
