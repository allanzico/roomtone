<?php

use App\Services\Qloo\QlooClient;
use App\Services\Qloo\QlooException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config(['services.qloo.key' => 'test-key', 'services.qloo.url' => 'https://qloo.test']);
    Cache::flush();
    Sleep::fake();
});

/** Take every Qloo slot except the first $free ones, as other workers would. */
function holdSlots(int $free = 0): array
{
    return array_map(function (int $slot) {
        $lock = Cache::lock("qloo:slot:{$slot}", 60);
        $lock->get();

        return $lock;
    }, range($free, QlooClient::CONCURRENCY - 1));
}

function searchHit(string $id, string $name, string $type): array
{
    return ['entity_id' => $id, 'name' => $name, 'types' => ["urn:entity:{$type}"], 'properties' => ['akas' => [['value' => 'x']]]];
}

function artistEntity(string $id, string $name, array $songs = []): array
{
    return [
        'entity_id' => $id,
        'name' => $name,
        'popularity' => 0.91234,
        'tags' => [
            ['id' => 'urn:tag:genre:qloo:country', 'name' => 'Country', 'type' => 'urn:tag:genre:qloo'],
            ['id' => 'urn:tag:style:qloo:soulful', 'name' => 'Soulful', 'type' => 'urn:tag:style:qloo'],
        ],
        'properties' => ['notable_songs' => $songs, 'biography' => 'long text we do not keep'],
    ];
}

test('candidates keep usable types, label cities as locations and drop the rest', function () {
    Http::fake(['qloo.test/search*' => Http::response(['results' => [
        searchHit('b1', 'Berlin', 'locality'),
        searchHit('b2', 'Berlin', 'album'),
        searchHit('b3', 'Berlin', 'artist'),
        searchHit('b4', 'Berlin', 'person'),
        searchHit('b5', 'Berlin', 'destination'),
    ]])]);

    expect(app(QlooClient::class)->candidates('Berlin'))->toBe([
        ['id' => 'b1', 'name' => 'Berlin', 'type' => 'locality', 'kind' => 'location'],
        ['id' => 'b3', 'name' => 'Berlin', 'type' => 'artist', 'kind' => 'entity'],
        ['id' => 'b5', 'name' => 'Berlin', 'type' => 'destination', 'kind' => 'location'],
    ]);

    Http::assertSent(fn (Request $r) => $r->hasHeader('X-Api-Key', 'test-key') && $r['query'] === 'Berlin');
});

test('candidates are capped', function () {
    Http::fake(['qloo.test/search*' => Http::response(['results' => array_map(
        fn (int $i) => searchHit("id{$i}", "Ozark {$i}", 'place'), range(1, 15),
    )])]);

    expect(app(QlooClient::class)->candidates('Ozark', 3))->toHaveCount(3);
});

test('artists sends signals, location and tags and returns slim artists', function () {
    Http::fake(['qloo.test/v2/insights*' => Http::response(['results' => ['entities' => [
        artistEntity('a1', 'Brent Cobb', ['Down Home', 'Shine On Rainy Day']),
        artistEntity('a2', 'Tems'),
    ]]])]);

    $artists = app(QlooClient::class)->artists(['t2', 't1'], 'Lagos', [QlooClient::MOODS['calm']], 10);

    expect($artists)->toBe([
        ['id' => 'a1', 'name' => 'Brent Cobb', 'popularity' => 0.912, 'songs' => ['Down Home', 'Shine On Rainy Day'], 'tags' => ['Country', 'Soulful'], 'genres' => ['Country']],
        ['id' => 'a2', 'name' => 'Tems', 'popularity' => 0.912, 'songs' => [], 'tags' => ['Country', 'Soulful'], 'genres' => ['Country']],
    ]);

    // Qloo wants literal dotted keys, which Laravel's own query parsing would mangle, so check the raw query.
    Http::assertSent(function (Request $r) {
        parse_str(strtr((string) parse_url($r->url(), PHP_URL_QUERY), ['.' => '__DOT__']), $q);
        $q = array_combine(str_replace('__DOT__', '.', array_keys($q)), $q);

        return $q['filter.type'] === 'urn:entity:artist'
            && $q['signal.interests.entities'] === 't1,t2'
            && $q['signal.location.query'] === 'Lagos'
            && $q['filter.tags'] === 'urn:tag:style:qloo:calm'
            && $q['take'] === '10';
    });
});

test('genres merge spellings, prefer the tidy one and skip non-genres', function () {
    $tag = fn (string $name, string $type = 'urn:tag:genre:qloo') => ['id' => 'x', 'name' => $name, 'type' => $type];
    Http::fake(['qloo.test/v2/insights*' => Http::response(['results' => ['entities' => [[
        'entity_id' => 'a1', 'name' => 'Burna Boy', 'properties' => [],
        'tags' => [
            $tag('Hip - Hop'), $tag('Afrobeats'), $tag('R & B', 'urn:tag:subgenre:qloo'), $tag('Hip Hop'),
            $tag('R&B'), $tag('All'), $tag('American'), $tag('Soulful', 'urn:tag:style:qloo'),
            // genre:music mixes in nationalities, so it is not read.
            $tag('Nigeria', 'urn:tag:genre:music'),
        ],
    ]]]])]);

    expect(app(QlooClient::class)->artists(['t1'])[0]['genres'])->toBe(['Hip Hop', 'Afrobeats', 'R & B']);
});

test('artists needs something to go on', function () {
    Http::fake();

    app(QlooClient::class)->artists();
})->throws(InvalidArgumentException::class);

test('tags returns id and name', function () {
    Http::fake(['qloo.test/v2/tags*' => Http::response(['results' => ['tags' => [
        ['id' => 'urn:tag:genre:music:afrobeats', 'name' => 'Afrobeats', 'type' => 'urn:tag:genre:music'],
    ]]])]);

    expect(app(QlooClient::class)->tags('afrobeats'))->toBe([['id' => 'urn:tag:genre:music:afrobeats', 'name' => 'Afrobeats']]);
});

test('identical requests hit Qloo once, whatever the order of the ids', function () {
    Http::fake(['qloo.test/v2/insights*' => Http::response(['results' => ['entities' => [artistEntity('a1', 'Tems')]]])]);

    $qloo = app(QlooClient::class);
    $qloo->artists(['x', 'y']);
    $qloo->artists(['y', 'x']);
    $qloo->artists(['x']);

    Http::assertSentCount(2);
});

test('failures raise a QlooException with Qloo\'s message and are not cached', function () {
    Http::fake(['qloo.test/v2/insights*' => Http::sequence()
        ->push(['errors' => [['message' => 'All of the provided signal.interests.entities were invalid.']]], 400)
        ->push(['results' => ['entities' => [artistEntity('a1', 'Tems')]]]),
    ]);

    $qloo = app(QlooClient::class);

    expect(fn () => $qloo->artists(['bad']))->toThrow(QlooException::class, 'All of the provided signal.interests.entities were invalid.');
    expect($qloo->artists(['bad']))->toHaveCount(1);
});

test('server errors are retried', function () {
    Http::fake(['qloo.test/search*' => Http::sequence()->push('boom', 503)->push(['results' => []])]);

    expect(app(QlooClient::class)->candidates('a'))->toBe([]);
    Http::assertSentCount(2);
});

test('client errors are not retried', function () {
    Http::fake(['qloo.test/search*' => Http::response(['errors' => [['message' => 'nope']]], 403)]);

    expect(fn () => app(QlooClient::class)->candidates('b'))->toThrow(QlooException::class, '403');
    Http::assertSentCount(1);
});

test('a missing key fails loudly', function () {
    config(['services.qloo.key' => null]);
    Http::fake();

    expect(fn () => app(QlooClient::class)->candidates('Ozark'))->toThrow(QlooException::class, 'QLOO_API_KEY');
    Http::assertNothingSent();
});

test('artistsFor sends an entity as a signal and a city as a location', function () {
    Http::fake(['qloo.test/v2/insights*' => Http::response(['results' => ['entities' => [artistEntity('a1', 'Tems')]]])]);

    $qloo = app(QlooClient::class);
    expect($qloo->artistsFor('entity', 'tv1'))->toHaveCount(1);
    expect($qloo->artistsFor('location', 'Lisbon'))->toHaveCount(1);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'signal.interests.entities=tv1') && ! str_contains($r->url(), 'location') && str_contains($r->url(), 'take=15'));
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'signal.location.query=Lisbon') && ! str_contains($r->url(), 'interests'));
});

test('artistsFor rejects an unknown kind', function () {
    Http::fake();

    app(QlooClient::class)->artistsFor('genre', 'jazz');
})->throws(InvalidArgumentException::class);

test('a 429 is retried with a growing wait', function () {
    Http::fake(['qloo.test/search*' => Http::sequence()
        ->push(['error_msg' => 'Rate limit exceeded'], 429)
        ->push(['error_msg' => 'Rate limit exceeded'], 429)
        ->push(['results' => [searchHit('t1', 'Ozark', 'tv_show')]]),
    ]);
    Log::spy();

    expect(app(QlooClient::class)->candidates('Ozark'))->toHaveCount(1);

    Log::shouldHaveReceived('warning')->twice();
    Http::assertSentCount(3);
    Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(2)->seconds()]);
});

test('a lasting 429 gives up with Qloo\'s error_msg', function () {
    Http::fake(['qloo.test/search*' => Http::response(['error_msg' => 'Rate limit exceeded'], 429)]);

    expect(fn () => app(QlooClient::class)->candidates('Ozark'))
        ->toThrow(QlooException::class, 'Qloo /search failed (429): Rate limit exceeded');
    Http::assertSentCount(5);
});

test('a call uses a free slot and gives it back', function () {
    $held = holdSlots(free: 1);
    Http::fake(['qloo.test/search*' => Http::response(['results' => []])]);

    app(QlooClient::class)->candidates('Ozark');

    Http::assertSentCount(1);
    expect(Cache::lock('qloo:slot:0', 1)->get())->toBeTrue();
    array_map(fn ($lock) => $lock->release(), $held);
});

test('a call waits while every slot is taken and goes once one frees up', function () {
    [$first] = holdSlots();
    $waits = 0;
    Sleep::whenFakingSleep(function () use ($first, &$waits) {
        if (++$waits === 3) {
            $first->release();
        }
    });
    Http::fake(['qloo.test/search*' => Http::response(['results' => []])]);

    app(QlooClient::class)->candidates('Ozark');

    expect($waits)->toBe(3);
    Http::assertSentCount(1);
});

test('a call gives up when no slot frees up', function () {
    holdSlots();
    Http::fake();

    expect(fn () => app(QlooClient::class)->candidates('Ozark'))->toThrow(QlooException::class, 'free Qloo slot');
    Http::assertNothingSent();
});

test('cached answers do not need a slot', function () {
    Http::fake(['qloo.test/search*' => Http::response(['results' => []])]);
    $qloo = app(QlooClient::class);
    $qloo->candidates('Ozark');

    holdSlots();

    expect($qloo->candidates('Ozark'))->toBe([]);
    Http::assertSentCount(1);
});
