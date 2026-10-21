<?php

use App\Enums\LoveStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\TasteCluster;
use App\Services\Dj\GuestClusterer;
use Database\Seeders\DemoPartySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->event = Event::create(['code' => 'CLUS01', 'name' => 'Test', 'host_token' => 'secret']);
});

/** A guest with one love whose pool has one artist per genre list, ranked in order. */
function genreGuest(Event $event, string $name, array $artistGenres): Guest
{
    $guest = $event->guests()->create(['name' => $name, 'color' => '#ffffff']);
    $guest->loves()->create([
        'raw_text' => "{$name}'s love",
        'status' => LoveStatus::Resolved,
        'pool' => array_map(fn (array $genres, int $i) => [
            'id' => "{$name}{$i}", 'name' => "{$name} Artist {$i}", 'songs' => ['Hit'], 'genres' => $genres, 'rank' => $i + 1,
        ], $artistGenres, array_keys($artistGenres)),
    ]);

    return $guest;
}

function islands(Event $event): array
{
    return $event->tasteClusters()->with('guests')->orderBy('id')->get()
        ->mapWithKeys(fn (TasteCluster $c) => [$c->label => $c->guests->pluck('name')->sort()->values()->all()])
        ->all();
}

function dominant(Guest $guest): ?string
{
    return $guest->clusters()->orderByPivot('weight', 'desc')->first()?->label;
}

test('genres everyone shares are discounted, so islands show what sets tastes apart', function () {
    genreGuest($this->event, 'Maria', [['Pop', 'Fado'], ['Pop']]);
    genreGuest($this->event, 'Tomás', [['Pop', 'Fado'], ['Pop']]);
    genreGuest($this->event, 'Aisha', [['Pop', 'Afrobeats'], ['Pop']]);
    genreGuest($this->event, 'Kofi', [['Pop', 'Afrobeats'], ['Pop']]);

    app(GuestClusterer::class)->cluster($this->event);

    expect(array_keys(islands($this->event)))->toEqualCanonicalizing(['Fado', 'Afrobeats'])
        ->and(islands($this->event)['Fado'])->toBe(['Maria', 'Tomás'])
        ->and(islands($this->event)['Afrobeats'])->toBe(['Aisha', 'Kofi']);
});

test('a genre only one guest has is not an island once there are three guests', function () {
    genreGuest($this->event, 'A', [['Fado', 'Jazz']]);
    genreGuest($this->event, 'B', [['Fado']]);
    genreGuest($this->event, 'C', [['Afrobeats', 'Fado']]);

    app(GuestClusterer::class)->cluster($this->event);

    expect(islands($this->event))->toBe(['Fado' => ['A', 'B', 'C']]);
});

test('spellings of one genre are one island, labelled with the tidy spelling', function () {
    genreGuest($this->event, 'A', [['Hip - Hop']]);
    genreGuest($this->event, 'B', [['Hip Hop']]);

    app(GuestClusterer::class)->cluster($this->event);

    expect(islands($this->event))->toBe(['Hip Hop' => ['A', 'B']]);
});

test('at most six islands; guests from smaller groups join their best match', function () {
    // Groups of 8, 7, 6, 5, 4, 3 and 2 guests; the two Tango fans also like Country.
    foreach (['Fado', 'Afrobeats', 'Country', 'Jazz', 'K Pop', 'Grime', 'Tango'] as $i => $genre) {
        foreach (range(1, 8 - $i) as $n) {
            genreGuest($this->event, "{$genre}{$n}", $genre === 'Tango' ? [['Tango'], ['Country']] : [[$genre]]);
        }
    }

    app(GuestClusterer::class)->cluster($this->event);
    $islands = islands($this->event);

    expect($islands)->toHaveCount(6)
        ->and($islands)->not->toHaveKey('Tango')
        ->and(dominant(Guest::where('name', 'Tango1')->sole()))->toBe('Country')
        ->and($this->event->tasteClusters()->orderBy('id')->pluck('color')->all())
        ->toBe(['#FF8A3D', '#FF4FD8', '#4C7DFF', '#35E8B0', '#FFC08F', '#9D8CFF']);
});

test('islands are chosen to cover the room, not just the biggest niches', function () {
    // Six pairs of niche fans, plus four guests who only share R&B with each other.
    foreach (['Fado', 'Afrobeats', 'Country', 'Jazz', 'K Pop', 'Grime'] as $genre) {
        genreGuest($this->event, "{$genre}1", [[$genre]]);
        genreGuest($this->event, "{$genre}2", [[$genre]]);
    }
    foreach (range(1, 4) as $n) {
        genreGuest($this->event, "RnB{$n}", [['R&B']]);
    }

    app(GuestClusterer::class)->cluster($this->event);

    expect(islands($this->event))->toHaveKey('R&B')
        ->and(islands($this->event)['R&B'])->toHaveCount(4);
});

test('a guest belongs most to their strongest island, and to at most three', function () {
    $maria = genreGuest($this->event, 'Maria', [['Fado'], ['Afrobeats'], ['Country'], ['Jazz']]);
    foreach (['Fado', 'Afrobeats', 'Country', 'Jazz'] as $genre) {
        genreGuest($this->event, "{$genre} fan", [[$genre]]);
        genreGuest($this->event, "{$genre} fan 2", [[$genre]]);
    }

    app(GuestClusterer::class)->cluster($this->event);

    $weights = $maria->clusters()->get()->mapWithKeys(fn ($c) => [$c->label => (float) $c->getRelationValue('pivot')->weight])->all();
    arsort($weights);

    expect($weights)->toHaveCount(3)
        ->and(array_key_first($weights))->toBe('Fado')
        ->and(reset($weights))->toBe(1.0)
        ->and(min($weights))->toBeGreaterThan(0)->toBeLessThan(1);
});

test('islands keep their id, colour and place as guests join, and empty ones go', function () {
    genreGuest($this->event, 'A', [['Fado']]);
    genreGuest($this->event, 'B', [['Fado']]);
    genreGuest($this->event, 'C', [['Country']]);
    genreGuest($this->event, 'D', [['Country']]);
    app(GuestClusterer::class)->cluster($this->event);
    $fado = TasteCluster::where('label', 'Fado')->sole();

    // Country fans leave the room; Afrobeats fans join.
    Guest::whereIn('name', ['C', 'D'])->delete();
    genreGuest($this->event, 'E', [['Afrobeats']]);
    genreGuest($this->event, 'F', [['Afrobeats']]);
    app(GuestClusterer::class)->cluster($this->event);

    $again = TasteCluster::where('label', 'Fado')->sole();
    expect($again->only(['id', 'color', 'position']))->toBe($fado->only(['id', 'color', 'position']))
        ->and(TasteCluster::where('label', 'Country')->exists())->toBeFalse()
        // Afrobeats takes the colour Country freed up.
        ->and(TasteCluster::where('label', 'Afrobeats')->sole()->color)->toBe('#FF8A3D');
});

test('guests without readable loves get no island', function () {
    genreGuest($this->event, 'A', [['Fado']]);
    genreGuest($this->event, 'B', [['Fado']]);
    $gran = $this->event->guests()->create(['name' => 'Gran', 'color' => '#ffffff']);
    $gran->loves()->create(['raw_text' => 'qxzv', 'status' => LoveStatus::Unresolved]);

    app(GuestClusterer::class)->cluster($this->event);

    expect($gran->clusters()->count())->toBe(0);
});

test('a party with no readable guests keeps its clusters (the seeded demo party)', function () {
    $this->seed(DemoPartySeeder::class);
    $demo = Event::where('code', 'DEMO')->sole();
    $before = islands($demo);

    app(GuestClusterer::class)->cluster($demo);

    expect(islands($demo))->toBe($before)->and($before)->not->toBeEmpty();
});
