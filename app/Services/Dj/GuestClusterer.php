<?php

namespace App\Services\Dj;

use App\Enums\LoveStatus;
use App\Models\Event;
use App\Models\GuestLove;
use App\Models\TasteCluster;
use App\Services\Qloo\QlooClient;
use Illuminate\Support\Facades\DB;

/**
 * Groups guests into at most 6 genre clusters (the host screen's islands), by code from Qloo's genre tags on the
 * artists in each guest's pools. Clusters are chosen to cover as many guests as possible. Genres most guests share ("Pop") are discounted, so the islands show what sets
 * the room's tastes apart. Labels are Qloo's own genre names. Clusters are matched by label across rebuilds, so
 * an island keeps its id, colour and place as guests join.
 */
class GuestClusterer
{
    public const MAX_CLUSTERS = 6;

    /** Clusters a guest belongs to, strongest first. */
    public const MAX_PER_GUEST = 3;

    /** Artists per love that count, weighted by rank (rank 1 counts 1, rank 8 counts 1/8). */
    private const ARTISTS_PER_LOVE = 8;

    /** Genre palette slots (docs/design/handoff.md), given out in order to new clusters. */
    private const PALETTE = ['#FF8A3D', '#FF4FD8', '#4C7DFF', '#35E8B0', '#FFC08F', '#9D8CFF'];

    /** Preferred floor spots for new clusters (x −7.2…7.2, z −3.05…3.05); the host screen pushes overlaps apart. */
    private const SPOTS = [[-4.8, 0.1], [2.9, 0.6], [-1.0, -1.2], [5.8, -1.9], [-5.6, -2.3], [0.9, 2.2]];

    public function cluster(Event $event): void
    {
        [$profiles, $spellings] = $this->profiles($event);

        // No readable guests yet (or a seeded demo party): leave the clusters as they are.
        if ($profiles === []) {
            return;
        }

        $scores = $this->scores($profiles);
        $kept = $this->choose($scores);
        $weights = $this->weights($scores, $kept);

        $this->save($event, $kept, $weights, $spellings);
    }

    /**
     * Each guest's genre profile: genre key => share of their weighted genre tags (sums to 1).
     *
     * @return array{0: array<int, array<string, float>>, 1: array<string, array<string, int>>}
     */
    private function profiles(Event $event): array
    {
        $profiles = [];
        $spellings = [];

        $loves = GuestLove::query()
            ->whereIn('guest_id', $event->guests()->select('id'))
            ->where('status', LoveStatus::Resolved)
            ->get();

        foreach ($loves as $love) {
            foreach (array_slice($love->pool ?? [], 0, self::ARTISTS_PER_LOVE) as $artist) {
                $weight = (self::ARTISTS_PER_LOVE + 1 - min($artist['rank'], self::ARTISTS_PER_LOVE)) / self::ARTISTS_PER_LOVE;

                foreach ($artist['genres'] ?? [] as $name) {
                    $key = QlooClient::genreKey($name);
                    $profiles[$love->guest_id][$key] = ($profiles[$love->guest_id][$key] ?? 0) + $weight;
                    $spellings[$key][$name] = ($spellings[$key][$name] ?? 0) + 1;
                }
            }
        }

        foreach ($profiles as $guest => $profile) {
            $total = array_sum($profile);
            $profiles[$guest] = array_map(fn (float $v) => $v / $total, $profile);
        }

        return [$profiles, $spellings];
    }

    /**
     * Profile shares discounted by how many guests have the genre (TF-IDF), so shared-by-all genres weigh little.
     *
     * @param  array<int, array<string, float>>  $profiles
     * @return array<int, array<string, float>>
     */
    private function scores(array $profiles): array
    {
        $guests = count($profiles);
        $held = [];

        foreach ($profiles as $profile) {
            foreach (array_keys($profile) as $key) {
                $held[$key] = ($held[$key] ?? 0) + 1;
            }
        }

        $scores = [];
        foreach ($profiles as $guest => $profile) {
            foreach ($profile as $key => $share) {
                // A genre only one guest has cannot make an island when there are 3 or more guests.
                $scores[$guest][$key] = $guests >= 3 && $held[$key] < 2 ? 0.0 : $share * log(($guests + 1) / $held[$key]);
            }
        }

        return $scores;
    }

    /**
     * Up to 6 genres, chosen greedily to cover the room: each step takes the genre that adds the most score among
     * guests not yet covered. A guest is covered by a genre at least half as strong as their own top genre.
     *
     * @param  array<int, array<string, float>>  $scores
     * @return list<string> Genre keys, in the order chosen.
     */
    private function choose(array $scores): array
    {
        $uncovered = array_filter($scores, fn (array $guestScores) => max([0.0, ...array_values($guestScores)]) > 0);
        $chosen = [];

        while (count($chosen) < self::MAX_CLUSTERS && $uncovered !== []) {
            $gains = [];
            foreach ($uncovered as $guestScores) {
                foreach ($guestScores as $key => $score) {
                    if (! in_array($key, $chosen, true)) {
                        $gains[$key] = ($gains[$key] ?? 0) + $score;
                    }
                }
            }

            $gains = array_filter($gains, fn (float $gain) => $gain > 0);

            if ($gains === []) {
                break;
            }

            uksort($gains, fn (string $a, string $b) => [$gains[$b], $a] <=> [$gains[$a], $b]);
            $key = (string) array_key_first($gains);
            $chosen[] = $key;

            $uncovered = array_filter($uncovered, fn (array $guestScores) => ($guestScores[$key] ?? 0) < 0.5 * max([0.0, ...array_values($guestScores)]));
        }

        return $chosen;
    }

    /**
     * Each guest's weight (0–1, strongest = 1) in up to 3 of the chosen clusters. Guests whose genres match none
     * of them get no cluster.
     *
     * @param  array<int, array<string, float>>  $scores
     * @param  list<string>  $kept
     * @return array<int, array<string, float>>
     */
    private function weights(array $scores, array $kept): array
    {
        $weights = [];

        foreach ($scores as $guest => $guestScores) {
            $mine = array_filter(array_intersect_key($guestScores, array_flip($kept)), fn (float $s) => $s > 0);
            arsort($mine);
            $mine = array_slice($mine, 0, self::MAX_PER_GUEST, true);

            if ($mine !== []) {
                $best = max($mine);
                $weights[$guest] = array_map(fn (float $s) => round($s / $best, 3), $mine);
            }
        }

        return $weights;
    }

    /**
     * @param  list<string>  $kept
     * @param  array<int, array<string, float>>  $weights
     * @param  array<string, array<string, int>>  $spellings
     */
    private function save(Event $event, array $kept, array $weights, array $spellings): void
    {
        DB::transaction(function () use ($event, $kept, $weights, $spellings) {
            // A base collection: Eloquent's only() filters by model id, not by these genre keys.
            $existing = $event->tasteClusters()->get()->keyBy(fn (TasteCluster $c) => QlooClient::genreKey($c->label))->toBase();
            $staying = $existing->only($kept);

            $event->tasteClusters()->whereNotIn('id', $staying->pluck('id'))->delete();

            $usedColors = $staying->pluck('color')->map(fn (string $c) => strtoupper($c))->all();
            $usedSpots = $staying->map(fn (TasteCluster $c) => [$c->position['x'], $c->position['z']])->values()->all();

            $ids = [];
            foreach ($kept as $key) {
                $cluster = $staying->get($key);

                if ($cluster === null) {
                    $color = collect(self::PALETTE)->first(fn (string $c) => ! in_array($c, $usedColors, true)) ?? self::PALETTE[0];
                    $spot = collect(self::SPOTS)->first(fn (array $s) => ! in_array($s, $usedSpots, true)) ?? self::SPOTS[0];
                    $usedColors[] = $color;
                    $usedSpots[] = $spot;

                    $cluster = $event->tasteClusters()->create([
                        'label' => $this->label($spellings[$key]),
                        'color' => $color,
                        'position' => ['x' => $spot[0], 'y' => 0, 'z' => $spot[1]],
                    ]);
                }

                $ids[$key] = $cluster->id;
            }

            foreach ($event->guests()->get() as $guest) {
                $sync = [];
                foreach ($weights[$guest->id] ?? [] as $key => $weight) {
                    $sync[$ids[$key]] = ['weight' => $weight];
                }

                $guest->clusters()->sync($sync);
            }
        });
    }

    /**
     * The most used spelling of a genre, preferring "Hip Hop" over "Hip - Hop".
     *
     * @param  array<string, int>  $spellings
     */
    private function label(array $spellings): string
    {
        uksort($spellings, fn (string $a, string $b) => [str_contains($a, ' - '), -$spellings[$a], $a] <=> [str_contains($b, ' - '), -$spellings[$b], $b]);

        return (string) array_key_first($spellings);
    }
}
