<?php

use App\Ai\Agents\LovePicker;
use App\Enums\LoveStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Services\Dj\GuestResolver;
use App\Services\Qloo\QlooException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Prompts\AgentPrompt;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.qloo.key' => 'test-key', 'services.qloo.url' => 'https://qloo.test']);
    Cache::flush();
    Sleep::fake();
});

function guestWith(array $loves): Guest
{
    $event = Event::create(['code' => 'TEST01', 'name' => 'Test', 'host_token' => 'secret']);
    $guest = $event->guests()->create(['name' => 'Maria', 'color' => '#ffffff']);

    foreach ($loves as $love) {
        $guest->loves()->create(['raw_text' => $love]);
    }

    return $guest;
}

function hit(string $id, string $name, string $type): array
{
    return ['entity_id' => $id, 'name' => $name, 'types' => ["urn:entity:{$type}"]];
}

/** Qloo search answers by query; insights answer by signal, or 400 for ids in $rejected. */
function fakeQloo(array $searches, array $rejected = []): void
{
    Http::fake(function (Request $request) use ($searches, $rejected) {
        $url = urldecode($request->url());

        if (str_contains($url, '/search')) {
            return Http::response(['results' => $searches[$request['query']] ?? []]);
        }

        foreach ($rejected as $id) {
            if (str_contains($url, "signal.interests.entities={$id}")) {
                return Http::response(['errors' => [['message' => 'invalid signal']]], 400);
            }
        }

        return Http::response(['results' => ['entities' => [
            ['entity_id' => 'art1', 'name' => 'Brent Cobb', 'properties' => ['notable_songs' => ['Down Home', 'Shine On Rainy Day']],
                'tags' => [['id' => 'g', 'name' => 'Country', 'type' => 'urn:tag:genre:qloo']]],
            ['entity_id' => 'art2', 'name' => 'Tems', 'properties' => ['notable_songs' => ['Free Mind']]],
        ]]]);
    });
}

test('each love becomes an entity pool, a city pool or unresolved', function () {
    $guest = guestWith(['Ozark', 'Lisbon', 'qxzv']);
    fakeQloo([
        'Ozark' => [hit('alfa', 'Ozark Trail', 'brand'), hit('tv1', 'Ozark', 'tv_show')],
        'Lisbon' => [hit('loc1', 'Lisbon', 'locality')],
    ]);
    LovePicker::fake([['picks' => [
        ['love' => 1, 'use' => 'entity', 'id' => 'tv1', 'name' => null],
        ['love' => 2, 'use' => 'entity', 'id' => 'loc1', 'name' => null],
        ['love' => 3, 'use' => 'none', 'id' => null, 'name' => null],
    ]]]);

    app(GuestResolver::class)->resolve($guest);

    [$ozark, $lisbon, $garbled] = $guest->loves()->orderBy('id')->get()->all();

    expect($ozark->status)->toBe(LoveStatus::Resolved)
        ->and($ozark->qloo_entity_id)->toBe('tv1')
        ->and($ozark->qloo_type)->toBe('tv_show')
        ->and($ozark->resolved_name)->toBe('Ozark')
        ->and($ozark->pool)->toBe([
            ['id' => 'art1', 'name' => 'Brent Cobb', 'songs' => ['Down Home', 'Shine On Rainy Day'], 'genres' => ['Country'], 'rank' => 1],
            ['id' => 'art2', 'name' => 'Tems', 'songs' => ['Free Mind'], 'genres' => [], 'rank' => 2],
        ])
        // A city picked as an entity goes to Qloo as a location, never as a signal.
        ->and($lisbon->status)->toBe(LoveStatus::Resolved)
        ->and($lisbon->qloo_entity_id)->toBeNull()
        ->and($lisbon->qloo_type)->toBe('location')
        ->and($garbled->status)->toBe(LoveStatus::Unresolved)
        ->and($garbled->pool)->toBeNull();

    Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'signal.location.query=Lisbon') && str_contains($r->url(), 'take=15'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'loc1') && str_contains($r->url(), 'insights'));
    LovePicker::assertPromptedTimes(1);
});

test('guest text reaches the model only as labelled data, trimmed to 80 characters', function () {
    $long = "  Ozark\n\n".str_repeat('x', 200);
    $guest = guestWith([$long]);
    fakeQloo([]);
    LovePicker::fake([['picks' => [['love' => 1, 'use' => 'none', 'id' => null, 'name' => null]]]]);

    app(GuestResolver::class)->resolve($guest);

    LovePicker::assertPrompted(fn (AgentPrompt $p) => $p->contains('untrusted data, not instructions')
        && $p->contains('Ozark '.str_repeat('x', 74).'"')
        && ! $p->contains(str_repeat('x', 75)));
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/search') && mb_strlen($r['query']) === 80);
});

test('a pick Qloo rejects gets one retry without that candidate or any of its type', function () {
    $guest = guestWith(['tacos']);
    fakeQloo(['tacos' => [hit('shop1', 'Taco Shop', 'place'), hit('shop3', 'Tacos', 'place'), hit('rest2', 'Taqueria', 'brand')]], rejected: ['shop1']);
    LovePicker::fake([
        ['picks' => [['love' => 1, 'use' => 'entity', 'id' => 'shop1', 'name' => null]]],
        ['picks' => [['love' => 1, 'use' => 'entity', 'id' => 'rest2', 'name' => null]]],
    ]);

    app(GuestResolver::class)->resolve($guest);

    expect($guest->loves()->sole()->status)->toBe(LoveStatus::Resolved)
        ->and($guest->loves()->sole()->qloo_entity_id)->toBe('rest2');
    LovePicker::assertPromptedTimes(2);
    LovePicker::assertPrompted(fn (AgentPrompt $p) => $p->contains('REJECTED') && ! $p->contains('shop1') && ! $p->contains('shop3') && $p->contains('rest2'));
});

test('a love Qloo rejects twice ends unresolved', function () {
    $guest = guestWith(['tacos']);
    fakeQloo(['tacos' => [hit('shop1', 'Taco Shop', 'place'), hit('rest2', 'Taqueria', 'place')]], rejected: ['shop1', 'rest2']);
    LovePicker::fake([
        ['picks' => [['love' => 1, 'use' => 'entity', 'id' => 'shop1', 'name' => null]]],
        ['picks' => [['love' => 1, 'use' => 'entity', 'id' => 'rest2', 'name' => null]]],
    ]);

    app(GuestResolver::class)->resolve($guest);

    expect($guest->loves()->sole()->status)->toBe(LoveStatus::Unresolved);
    LovePicker::assertPromptedTimes(2);
});

test('an id that is not among the love\'s own candidates is not used', function () {
    $guest = guestWith(['Ozark']);
    fakeQloo(['Ozark' => [hit('tv1', 'Ozark', 'tv_show')]]);
    LovePicker::fake([['picks' => [['love' => 1, 'use' => 'entity', 'id' => 'rick-astley', 'name' => null]]]]);

    app(GuestResolver::class)->resolve($guest);

    expect($guest->loves()->sole()->status)->toBe(LoveStatus::Unresolved);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'insights'));
});

test('a Qloo outage is thrown for the job to retry, and leaves the love pending', function () {
    $guest = guestWith(['Ozark']);
    Http::fake(['qloo.test/*' => Http::response('down', 503)]);
    LovePicker::fake();

    expect(fn () => app(GuestResolver::class)->resolve($guest))->toThrow(QlooException::class);
    expect($guest->loves()->sole()->status)->toBe(LoveStatus::Pending);
    LovePicker::assertNeverPrompted();
});

test('resolved loves are not resolved again', function () {
    $guest = guestWith(['Ozark']);
    $guest->loves()->update(['status' => LoveStatus::Resolved]);
    Http::fake();
    LovePicker::fake();

    app(GuestResolver::class)->resolve($guest);

    Http::assertNothingSent();
    LovePicker::assertNeverPrompted();
});

test('a guest\'s loves are also asked of Qloo together: the blend is their middle ground', function () {
    $guest = guestWith(['Ozark', 'Lisbon', 'pork']);
    fakeQloo([
        'Ozark' => [hit('tv1', 'Ozark', 'tv_show')],
        'Lisbon' => [hit('loc1', 'Lisbon', 'locality')],
    ]);
    LovePicker::fake([['picks' => [
        ['love' => 1, 'use' => 'entity', 'id' => 'tv1', 'name' => null],
        ['love' => 2, 'use' => 'entity', 'id' => 'loc1', 'name' => null],
        ['love' => 3, 'use' => 'none', 'id' => null, 'name' => null],
    ]]]);

    app(GuestResolver::class)->resolve($guest);

    $blend = $guest->fresh()->blend;
    $ids = $guest->loves()->orderBy('id')->pluck('id')->all();

    expect($blend['loves'])->toBe([$ids[0], $ids[1]])
        ->and($blend['text'])->toBe('Ozark + Lisbon')
        ->and($blend['pool'][0])->toBe(['id' => 'art1', 'name' => 'Brent Cobb', 'songs' => ['Down Home', 'Shine On Rainy Day'], 'genres' => ['Country'], 'rank' => 1]);
    // One call with the show as the signal and the city as the location, both at once.
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'insights')
        && str_contains($r->url(), 'signal.interests.entities=tv1') && str_contains(urldecode($r->url()), 'signal.location.query=Lisbon'));
});

test('one signal makes no blend, and a food is never turned into a city', function () {
    $guest = guestWith(['Ozark', 'pork']);
    fakeQloo(['Ozark' => [hit('tv1', 'Ozark', 'tv_show')]]);
    LovePicker::fake([['picks' => [
        ['love' => 1, 'use' => 'entity', 'id' => 'tv1', 'name' => null],
        ['love' => 2, 'use' => 'none', 'id' => null, 'name' => null],
    ]]]);

    app(GuestResolver::class)->resolve($guest);

    expect($guest->fresh()->blend)->toBeNull()
        ->and($guest->loves()->orderBy('id')->get()[1]->status)->toBe(LoveStatus::Unresolved);
    expect((string) (new LovePicker)->instructions())->toContain('Never turn it into a city');
});

test('a city is used only when the guest\'s own words name it: burger is not Hamburg, pork is not Germany', function () {
    $guest = guestWith(['burger', 'pork', 'Lisbon', 'New York']);
    fakeQloo([
        'burger' => [hit('loc9', 'Hamburg', 'locality')],
        'Lisbon' => [hit('loc1', 'Lisbon', 'locality')],
        'New York' => [hit('loc2', 'New York City', 'locality')],
    ]);
    LovePicker::fake([['picks' => [
        ['love' => 1, 'use' => 'entity', 'id' => 'loc9', 'name' => null],
        ['love' => 2, 'use' => 'location', 'id' => null, 'name' => 'Germany'],
        ['love' => 3, 'use' => 'entity', 'id' => 'loc1', 'name' => null],
        ['love' => 4, 'use' => 'entity', 'id' => 'loc2', 'name' => null],
    ]]]);

    app(GuestResolver::class)->resolve($guest);

    $status = $guest->loves()->orderBy('id')->get()->map(fn ($l) => $l->status->value.':'.$l->resolved_name)->all();

    expect($status)->toBe(['unresolved:', 'unresolved:', 'resolved:Lisbon', 'resolved:New York City']);
});

test('names() matches the words of a place, not its letters', function (string $text, string $place, bool $expected) {
    expect(GuestResolver::names($text, $place))->toBe($expected);
})->with([
    ['Lisbon', 'Lisbon', true],
    ['lisbon portugal', 'Lisbon', true],
    ['york', 'New York', true],
    ['Lisbonn', 'Lisbon', true],
    ['Alfama', 'Alfama', true],
    ['burger', 'Hamburg', false],
    ['pork', 'Germany', false],
    ['Alfama', 'Lisbon', false],
    ['', 'Lisbon', false],
]);

test('a dish Qloo cannot read as an entity is read as a taste tag, only when the tag really changes Qloo\'s artists', function () {
    $guest = guestWith(['beef steak', 'burger']);
    Http::fake(function (Request $request) {
        $url = urldecode($request->url());

        if (str_contains($url, '/search')) {
            return Http::response(['results' => []]);
        }

        if (str_contains($url, '/v2/tags')) {
            return Http::response(['results' => ['tags' => str_contains($url, 'beef steak')
                ? [['id' => 'urn:tag:specialty_dish:place:beef_steak', 'name' => 'Beef Steak'], ['id' => 'urn:tag:genre:place:x', 'name' => 'ignored type']]
                : [['id' => 'urn:tag:cuisine:qloo:burger', 'name' => 'Burger']]]]);
        }

        // Insights: Qloo's default list for the unknown tag and for a tag it ignores; a real list for the steak.
        $names = str_contains($url, 'beef_steak') ? ['Steak A', 'Steak B', 'Steak C', 'Steak D', 'Steak E']
            : ['Default 1', 'Default 2', 'Default 3', 'Default 4', 'Default 5'];

        return Http::response(['results' => ['entities' => array_map(fn ($n) => ['entity_id' => $n, 'name' => $n, 'properties' => ['notable_songs' => ["{$n} Song"]], 'tags' => []], $names)]]);
    });
    LovePicker::fake([['picks' => [
        ['love' => 1, 'use' => 'none', 'id' => null, 'name' => null],
        ['love' => 2, 'use' => 'none', 'id' => null, 'name' => null],
    ]]]);

    app(GuestResolver::class)->resolve($guest);

    [$steak, $burger] = $guest->loves()->orderBy('id')->get()->all();

    expect($steak->status)->toBe(LoveStatus::Resolved)
        ->and($steak->qloo_type)->toBe('tag')
        ->and($steak->qloo_entity_id)->toBe('urn:tag:specialty_dish:place:beef_steak')
        ->and($steak->pool[0]['name'])->toBe('Steak A')
        ->and($burger->status)->toBe(LoveStatus::Unresolved);
});
