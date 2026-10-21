<?php

namespace App\Services\Dj;

use App\Models\Event;
use Carbon\CarbonImmutable;

/**
 * The energy arc: the host's plan ("20:00 arrivals 3, 23:00 peak 5, 00:30 wind down 2") turned into a target
 * energy for each upcoming track, and a set ordered to follow it. Energy is the model's estimate per track (Qloo
 * has none), so this is a shape, not a promise.
 */
class EnergyArc
{
    /** Rough length of a track, to place upcoming slots on the clock. */
    public const MINUTES_PER_TRACK = 3.5;

    /** Without a plan: warm up, build, peak, wind down, counted from when the event was created. */
    private const DEFAULT_PLAN = [[0, 3], [60, 4], [150, 5], [240, 2]];

    /**
     * Target energy (1–5) for each of $count tracks starting at $from, stepping between plan points.
     *
     * @return list<int>
     */
    public static function targets(Event $event, CarbonImmutable $from, int $count): array
    {
        $points = self::points($event);

        // After midnight, a plan that "starts" tonight at 20:00 is really last night's party.
        if ($points[0][0]->subHours(6)->greaterThan($from)) {
            $points = array_map(fn (array $p) => [$p[0]->subDay(), $p[1]], $points);
        }

        $targets = [];

        for ($i = 0; $i < $count; $i++) {
            $at = $from->addSeconds((int) round($i * self::MINUTES_PER_TRACK * 60));
            $energy = $points[0][1];

            foreach ($points as [$time, $level]) {
                if ($time->lessThanOrEqualTo($at)) {
                    $energy = $level;
                }
            }

            $targets[] = $energy;
        }

        return $targets;
    }

    /**
     * Order tracks to follow the targets: each slot takes the remaining track closest in energy, never the same
     * guest twice in a row when there is a choice. Ties keep the agent's order.
     *
     * @template T of array{energy: int, for_guest_id: int}
     *
     * @param  list<T>  $tracks
     * @param  list<int>  $targets
     * @return list<T>
     */
    public static function order(array $tracks, array $targets, ?int $previousGuest = null): array
    {
        $ordered = [];

        foreach (array_keys($tracks) as $slot) {
            $target = $targets[$slot] ?? end($targets) ?: 3;
            $choices = array_filter($tracks, fn (array $t) => $t['for_guest_id'] !== $previousGuest) ?: $tracks;

            $best = array_key_first($choices);

            if ($best === null) {
                break;
            }

            foreach ($choices as $key => $track) {
                if (abs($track['energy'] - $target) < abs($choices[$best]['energy'] - $target)) {
                    $best = $key;
                }
            }

            $ordered[] = $tracks[$best];
            $previousGuest = $tracks[$best]['for_guest_id'];
            unset($tracks[$best]);
        }

        return $ordered;
    }

    /**
     * Plan points as absolute times, in order. Clock times after midnight count as the next day.
     *
     * @return list<array{0: CarbonImmutable, 1: int}>
     */
    private static function points(Event $event): array
    {
        if ($event->energy_plan === null || $event->energy_plan === []) {
            $start = CarbonImmutable::instance($event->created_at ?? now());

            return array_map(fn (array $p) => [$start->addMinutes($p[0]), $p[1]], self::DEFAULT_PLAN);
        }

        $points = [];
        $previous = null;

        foreach ($event->energy_plan as $point) {
            $time = CarbonImmutable::parse($point['at']);

            // "00:30" after "23:00" is the next night, not this morning.
            while ($previous !== null && $time->lessThan($previous)) {
                $time = $time->addDay();
            }

            $points[] = [$time, max(1, min(5, (int) $point['energy']))];
            $previous = $time;
        }

        return $points;
    }
}
