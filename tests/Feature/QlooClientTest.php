<?php

use App\Services\Qloo\QlooClient;
use App\Services\Qloo\QlooException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.qloo.key' => 'test-key', 'services.qloo.url' => 'https://qloo.test']);
    Cache::flush();
});

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
        'tags' => [['id' => 'urn:tag:genre:qloo:country', 'name' => 'Country']],
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
        ['id' => 'a1', 'name' => 'Brent Cobb', 'popularity' => 0.912, 'songs' => ['Down Home', 'Shine On Rainy Day'], 'tags' => ['Country']],
        ['id' => 'a2', 'name' => 'Tems', 'popularity' => 0.912, 'songs' => [], 'tags' => ['Country']],
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
