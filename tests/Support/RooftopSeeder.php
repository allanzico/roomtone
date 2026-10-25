<?php

namespace Tests\Support;

use App\Enums\SetItemStatus;
use App\Models\Event;
use App\Models\Track;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A fixture for the tests: the "Lisbon Rooftop" party (event code ROOF01) with fake guests, islands and a set.
 * Re-running it replaces the event; the global track cache is updated in place.
 */
class RooftopSeeder extends Seeder
{
    public function run(): void
    {
        Event::query()->where('code', 'ROOF01')->delete();

        $event = Event::query()->create([
            'code' => 'ROOF01',
            'name' => 'Lisbon Rooftop',
            'occasion' => 'Rooftop birthday',
            'energy_plan' => [
                ['at' => '20:00', 'energy' => 3, 'label' => 'Arrivals'],
                ['at' => '21:30', 'energy' => 4, 'label' => 'After dinner'],
                ['at' => '23:00', 'energy' => 5, 'label' => 'Peak'],
                ['at' => '00:30', 'energy' => 2, 'label' => 'Wind down'],
            ],
            'host_token' => Str::random(40),
        ]);

        // Positions are spots on the host screen's floor: x −7.2…7.2 left to right, z −3…3 back to front.
        $clusters = collect([
            'afro' => ['label' => 'Afrobeats', 'color' => '#FF8A3D', 'position' => ['x' => 2.9, 'y' => 0, 'z' => 0.65]],
            'pop' => ['label' => '80s Pop', 'color' => '#FF4FD8', 'position' => ['x' => -4.85, 'y' => 0, 'z' => 0.1]],
            'fado' => ['label' => 'Fado', 'color' => '#4C7DFF', 'position' => ['x' => -1.0, 'y' => 0, 'z' => -1.15]],
            'garage' => ['label' => 'UK Garage', 'color' => '#35E8B0', 'position' => ['x' => 5.8, 'y' => 0, 'z' => -1.85]],
        ])->map(fn (array $cluster) => $event->tasteClusters()->create($cluster));

        $guests = collect([
            'maria' => ['Maria', '#FF5D73', ['Ozark', 'pastéis de nata', 'Burna Boy'], ['afro' => 0.7, 'fado' => 0.3]],
            'tomas' => ['Tomás', '#7FE7FF', ['Alfama', 'grilled sardines', 'Amália Rodrigues'], ['fado' => 0.85, 'pop' => 0.15]],
            'aisha' => ['Aisha', '#FFD166', ['jollof rice', 'Lagos', 'Wizkid'], ['afro' => 0.8, 'garage' => 0.2]],
            'jake' => ['Jake', '#9D8CFF', ['Stranger Things', 'Whitney Houston', 'Coney Island'], ['pop' => 0.9, 'afro' => 0.1]],
            'priya' => ['Priya', '#FF9F6E', ['Peckham', 'Top Boy', 'Craig David'], ['garage' => 0.75, 'afro' => 0.25]],
            'lena' => ['Lena', '#6EF7C8', ['Dark', 'Berlin', 'Kate Bush'], ['pop' => 0.6, 'garage' => 0.4]],
            'kofi' => ['Kofi', '#B6F36B', ['suya', 'Insecure', 'Tems'], ['afro' => 0.55, 'garage' => 0.45]],
            'ines' => ['Inês', '#F7A8FF', ['Sintra', 'The White Lotus', 'Mariza'], ['fado' => 0.7, 'pop' => 0.3]],
        ])->map(function (array $guest) use ($event, $clusters) {
            [$name, $color, $loves, $weights] = $guest;

            $model = $event->guests()->create(['name' => $name, 'color' => $color]);
            $model->loves()->createMany(array_map(fn (string $love) => ['raw_text' => $love], $loves));

            foreach ($weights as $cluster => $weight) {
                $model->clusters()->attach($clusters[$cluster]->id, ['weight' => $weight]);
            }

            return $model;
        });

        $set = [
            ['Burna Boy', 'Last Last', 4, 'maria', 'because Maria loves Ozark'],
            ['Whitney Houston', 'I Wanna Dance with Somebody (Who Loves Me)', 5, 'jake', 'because Jake loves Stranger Things'],
            ['Craig David', 'Fill Me In', 4, 'priya', 'because Priya loves Peckham'],
            ['Amália Rodrigues', 'Barco Negro', 2, 'tomas', 'because Tomás loves grilled sardines'],
            ['Wizkid', 'Essence', 3, 'aisha', 'because Aisha loves jollof rice'],
            ['Kate Bush', 'Running Up That Hill (A Deal with God)', 3, 'lena', 'because Lena loves Dark'],
            ['Artful Dodger', 'Re-Rewind', 5, 'kofi', 'because Kofi loves Insecure'],
            ['Mariza', 'Ó Gente da Minha Terra', 2, 'ines', 'because Inês loves Sintra'],
            ['Tems', 'Free Mind', 2, 'kofi', 'because Kofi loves suya'],
            ['Cyndi Lauper', 'Girls Just Want to Have Fun', 5, 'ines', 'because Inês loves The White Lotus'],
        ];

        foreach ($set as $position => [$artist, $title, $energy, $guest, $reason]) {
            $track = Track::query()->updateOrCreate(
                ['artist' => $artist, 'title' => $title],
                ['energy' => $energy],
            );

            $event->setItems()->create([
                'track_id' => $track->id,
                'position' => $position + 1,
                'status' => $position === 0 ? SetItemStatus::Playing : SetItemStatus::Queued,
                'reason_guest_id' => $guests[$guest]->id,
                'reason_text' => $reason,
            ]);
        }
    }
}
