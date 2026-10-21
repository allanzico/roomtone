<?php

namespace App\Services\Dj;

use App\Models\Event;
use App\Models\Guest;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Creates a party from a file of guests and resolves everyone at once (stage 1), for roomtone:party and the
 * demo build. Party files hold fictional guests only, never Qloo data.
 *
 * @phpstan-type PartyFile array{name: string, occasion?: string, guests: list<array{name: string, loves: list<string>}>}
 */
class PartyRunner
{
    /**
     * @param  PartyFile  $party
     * @param  list<array{at: string, energy: int, label: string}>|null  $plan
     */
    public function create(array $party, ?array $plan = null): Event
    {
        do {
            $code = Str::upper(Str::random(6));
        } while (Event::where('code', $code)->exists());

        $event = Event::create([
            'code' => $code,
            'name' => $party['name'],
            'occasion' => $party['occasion'] ?? null,
            'energy_plan' => $plan,
            'host_token' => Str::random(40),
        ]);

        foreach ($party['guests'] as $entry) {
            $guest = $event->guests()->create(['name' => $entry['name'], 'color' => '#ffffff']);

            foreach ($entry['loves'] as $love) {
                $guest->loves()->create(['raw_text' => $love]);
            }
        }

        return $event;
    }

    /**
     * Resolve every guest, $workers at a time in separate processes, like a room of people joining at once. The
     * Qloo cap is shared through cache locks.
     */
    public function resolveAll(Event $event, int $workers = 5): TextUsage
    {
        $ids = $event->guests()->orderBy('id')->pluck('id')->all();
        $slices = array_filter(array_map(fn (int $w) => array_values(array_filter($ids, fn ($id, $i) => $i % $workers === $w, ARRAY_FILTER_USE_BOTH)), range(0, $workers - 1)));

        $tasks = [];
        foreach (array_values($slices) as $slice) {
            $tasks[] = static function () use ($slice): array {
                $total = new TextUsage;
                foreach ($slice as $id) {
                    $total = $total->add(app(GuestResolver::class)->resolve(Guest::query()->whereKey($id)->firstOrFail()));
                }

                return $total->toArray();
            };
        }

        $results = Concurrency::run($tasks, 900);

        return array_reduce($results, fn (TextUsage $carry, array $usage) => $carry->add(TextUsage::fromArray($usage)), new TextUsage);
    }
}
