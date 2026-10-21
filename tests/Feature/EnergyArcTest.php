<?php

use App\Models\Event;
use App\Services\Dj\EnergyArc;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function arcEvent(?array $plan): Event
{
    return Event::create(['code' => 'ARC'.random_int(100, 999), 'name' => 'x', 'host_token' => Str::random(40), 'energy_plan' => $plan]);
}

$plan = [
    ['at' => '20:00', 'energy' => 3, 'label' => 'Arrivals'],
    ['at' => '21:30', 'energy' => 4, 'label' => 'After dinner'],
    ['at' => '23:00', 'energy' => 5, 'label' => 'Peak'],
    ['at' => '00:30', 'energy' => 2, 'label' => 'Wind down'],
];

test('each upcoming track gets the energy of the plan step it falls in', function () use ($plan) {
    $this->travelTo(CarbonImmutable::parse('today 22:50'));

    // 3.5 minutes a track: 22:50, 22:53:30, 22:57, 23:00:30 (peak), 23:04 …
    expect(EnergyArc::targets(arcEvent($plan), CarbonImmutable::now(), 5))->toBe([4, 4, 4, 5, 5]);
});

test('the plan crosses midnight, and after midnight it is still the same party', function () use ($plan) {
    $this->travelTo(CarbonImmutable::parse('today 23:58'));
    expect(EnergyArc::targets(arcEvent($plan), CarbonImmutable::now(), 1))->toBe([5]);

    $this->travelTo(CarbonImmutable::parse('tomorrow 00:40'));
    expect(EnergyArc::targets(arcEvent($plan), CarbonImmutable::now(), 1))->toBe([2]);
});

test('before the first step the party is at its first energy', function () use ($plan) {
    $this->travelTo(CarbonImmutable::parse('today 18:30'));

    expect(EnergyArc::targets(arcEvent($plan), CarbonImmutable::now(), 2))->toBe([3, 3]);
});

test('without a plan the default arc warms up, builds, peaks and winds down from the event\'s start', function () {
    $this->travelTo(CarbonImmutable::parse('today 19:00'));
    $event = arcEvent(null);

    expect(EnergyArc::targets($event, CarbonImmutable::now(), 1))->toBe([3])
        ->and(EnergyArc::targets($event, CarbonImmutable::now()->addMinutes(61), 1))->toBe([4])
        ->and(EnergyArc::targets($event, CarbonImmutable::now()->addMinutes(151), 1))->toBe([5])
        ->and(EnergyArc::targets($event, CarbonImmutable::now()->addMinutes(241), 1))->toBe([2]);
});

test('the set is ordered to follow the targets, never the same guest twice in a row', function () {
    $t = fn (string $id, int $energy, int $guest) => ['id' => $id, 'energy' => $energy, 'for_guest_id' => $guest];
    $tracks = [$t('a', 5, 1), $t('b', 2, 1), $t('c', 3, 2), $t('d', 5, 1), $t('e', 4, 3)];

    $ordered = EnergyArc::order($tracks, [3, 4, 5, 5, 2]);

    expect(array_column($ordered, 'id'))->toBe(['c', 'e', 'a', 'd', 'b']);
    // All peak: after a (guest 1) the next can't be guest 1, so e (4) comes before d (5).
    expect(array_column(EnergyArc::order($tracks, [5, 5, 5, 5, 5]), 'id'))->toBe(['a', 'e', 'd', 'c', 'b']);
});

test('the guest playing now does not get the next track when another guest can', function () {
    $t = fn (string $id, int $energy, int $guest) => ['id' => $id, 'energy' => $energy, 'for_guest_id' => $guest];

    expect(array_column(EnergyArc::order([$t('a', 4, 7), $t('b', 2, 8)], [4, 4], previousGuest: 7), 'id'))->toBe(['b', 'a']);
});
