<?php

namespace App\Services\Dj;

use Illuminate\Support\Str;

/**
 * What the setup chat has agreed so far, checked: the model (or the host's own edits) only ever proposes; this
 * decides what is kept. Times snap to 15 minutes, energy stays 1–5, labels and names stay short.
 *
 * @phpstan-type Point array{at: string|int, energy: int, label: string}
 * @phpstan-type Draft array{occasion: string|null, name: string|null, timeline: 'clock'|'trip', minutes: int|null, points: list<Point>}
 */
class SetupDraft
{
    public const OCCASIONS = ['party', 'birthday', 'drive', 'road trip', 'dinner', 'cooking', 'workout', 'other'];

    private const MAX_POINTS = 6;

    /**
     * @param  array<mixed>  $input
     * @return Draft
     */
    public static function from(array $input): array
    {
        $timeline = ($input['timeline'] ?? null) === 'trip' ? 'trip' : 'clock';
        $minutes = is_numeric($input['minutes'] ?? null) ? self::snap((int) $input['minutes'], 15, 1440) : null;

        $points = [];
        foreach (array_slice(array_values(array_filter((array) ($input['points'] ?? []), 'is_array')), 0, self::MAX_POINTS) as $point) {
            $at = $timeline === 'trip' ? self::tripAt($point['at'] ?? null) : self::clockAt($point['at'] ?? null);

            if ($at === null) {
                continue;
            }

            $points[] = [
                'at' => $at,
                'energy' => max(1, min(5, (int) ($point['energy'] ?? 3))),
                'label' => Str::limit(trim((string) ($point['label'] ?? '')), 24, ''),
            ];
        }

        if ($timeline === 'trip') {
            usort($points, fn (array $a, array $b) => $a['at'] <=> $b['at']);
            $minutes ??= $points === [] ? null : max(15, (int) end($points)['at']);
        }

        $occasion = is_string($input['occasion'] ?? null) && in_array($input['occasion'], self::OCCASIONS, true) ? $input['occasion'] : null;
        $name = is_string($input['name'] ?? null) ? Str::limit(trim($input['name']), 60, '') : '';

        return [
            'occasion' => $occasion,
            'name' => $name === '' ? null : $name,
            'timeline' => $timeline,
            'minutes' => $timeline === 'trip' ? $minutes : null,
            'points' => $points,
        ];
    }

    /**
     * Enough to open a room: a plan with at least two points.
     *
     * @param  Draft  $draft
     */
    public static function ready(array $draft): bool
    {
        return count($draft['points']) >= 2;
    }

    private static function clockAt(mixed $at): ?string
    {
        if (! is_string($at) || preg_match('/^(\d{1,2}):(\d{2})$/', trim($at), $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        $minutes = self::snap((int) $m[1] * 60 + (int) $m[2], 0, 24 * 60) % (24 * 60);

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private static function tripAt(mixed $at): ?int
    {
        return is_numeric($at) ? self::snap((int) $at, 0, 1440) : null;
    }

    private static function snap(int $minutes, int $min, int $max): int
    {
        return max($min, min($max, (int) (round($minutes / 15) * 15)));
    }
}
