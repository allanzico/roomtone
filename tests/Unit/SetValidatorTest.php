<?php

use App\Services\Dj\SetValidator;

/** Three guests: Maria (1) loves Ozark (10) and Lisbon (11); Jake (2) loves Dark (20); Gran (3) has no pools. */
function djValidator(int $target = 6, array $used = [], ?Closure $video = null, array $typed = []): SetValidator
{
    $artist = fn (string $id, string $name, int $rank, array $songs = ['Hit', 'Deep Cut']) => compact('id', 'name', 'songs', 'rank');

    return new SetValidator(
        guests: [1 => 'Maria', 2 => 'Jake', 3 => 'Gran'],
        loves: [
            10 => ['id' => 10, 'guest_id' => 1, 'text' => 'Ozark', 'pool' => [
                $artist('a1', 'Brent Cobb', 1, ['Down Home', 'Shine On Rainy Day']),
                $artist('a2', 'Tems', 2),
                $artist('a9', 'Shared Act', 7),
            ]],
            11 => ['id' => 11, 'guest_id' => 1, 'text' => 'Lisbon', 'pool' => [$artist('a3', 'Mariza', 1)]],
            20 => ['id' => 20, 'guest_id' => 2, 'text' => 'Dark', 'pool' => [
                $artist('a4', 'Kate Bush', 1),
                $artist('a9', 'Shared Act', 3),
            ]],
        ],
        typed: ['Ozark', 'Lisbon', 'Dark', 'that feeling when it rains', ...$typed],
        target: $target,
        usedArtists: $used,
        playableTitle: $video,
    );
}

function djTrack(array $overrides = []): array
{
    return ['artist' => 'Brent Cobb', 'title' => 'Down Home', 'for_guest' => 1, 'love' => 10, 'energy' => 3, 'bpm' => 100, ...$overrides];
}

test('a track from a stored pool is accepted and code writes the reason', function () {
    $v = djValidator();

    expect($v->add(djTrack(['artist' => 'brent cobb', 'title' => 'down home'])))->toMatchArray(['accepted' => true, 'title' => 'Down Home']);
    expect($v->accepted()[0])->toMatchArray([
        'artist' => 'Brent Cobb', 'title' => 'Down Home', 'qloo_artist_id' => 'a1', 'for_guest_id' => 1, 'love_id' => 10,
        'rank' => 1, 'reason' => 'because Maria loves Ozark', 'bridge' => false,
    ]);
});

test('malformed arguments are rejected', function (array $args) {
    $v = djValidator();

    expect($v->add(djTrack($args))['accepted'])->toBeFalse()->and($v->log())->toBe(['bad_args']);
})->with([
    'missing artist' => [['artist' => null]],
    'guest as a name' => [['for_guest' => 'Maria']],
    'energy as text' => [['energy' => 'high']],
]);

test('a love with no stored pool is rejected', function () {
    $v = djValidator();

    expect($v->add(djTrack(['love' => 99]))['error'])->toContain('no stored Qloo results for love 99')->and($v->log())->toBe(['not_stored']);
});

test('an artist outside the pool is rejected with the pool\'s top results', function () {
    $v = djValidator();

    expect($v->add(djTrack(['artist' => 'Rick Astley', 'title' => 'Never Gonna Give You Up']))['error'])
        ->toContain('not in Qloo\'s results for love 10')->toContain('Brent Cobb, Tems, Shared Act');
    expect($v->log())->toBe(['not_in_results']);
});

test('a title outside the artist\'s notable songs is rejected with the list', function () {
    $v = djValidator();

    expect($v->add(djTrack(['title' => 'Made Up Song']))['error'])->toContain('Down Home; Shine On Rainy Day');
    expect($v->log())->toBe(['title_not_notable']);
});

test('an unknown guest is rejected', function () {
    $v = djValidator();

    $v->add(djTrack(['for_guest' => 42]));
    expect($v->log())->toBe(['bad_guest']);
});

test('a track for another guest must be in one of their own pools', function () {
    $v = djValidator();

    expect($v->add(djTrack(['for_guest' => 2]))['error'])->toContain('not in any of guest 2\'s results');
    expect($v->log())->toBe(['not_a_bridge']);
});

test('a bridge names both guests\' loves', function () {
    $v = djValidator();

    $v->add(djTrack(['artist' => 'Shared Act', 'title' => 'Hit', 'for_guest' => 2, 'love' => 10]));

    expect($v->accepted()[0])->toMatchArray([
        'for_guest_id' => 2, 'love_id' => 10, 'rank' => 7, 'bridge' => true,
        'reason' => 'because Jake loves Dark and Maria loves Ozark',
    ]);
});

test('a reason also names another guest whose love ranks the artist in Qloo\'s top 5', function () {
    $v = djValidator();

    // Shared Act is #7 for Maria's Ozark and #3 for Jake's Dark.
    $v->add(djTrack(['artist' => 'Shared Act', 'title' => 'Hit']));
    expect($v->accepted()[0])->toMatchArray(['reason' => 'because Maria loves Ozark and Jake loves Dark', 'bridge' => true]);

    // Tems is only in Maria's results.
    $v->add(djTrack(['artist' => 'Tems', 'title' => 'Hit', 'love' => 10]));
    expect($v->accepted()[1])->toMatchArray(['reason' => 'because Maria loves Ozark', 'bridge' => false]);
});

test('a second guest beyond rank 5 is not named', function () {
    $v = djValidator();

    // Shared Act is #7 for Maria: too weak to name her on Jake's track.
    $v->add(djTrack(['artist' => 'Shared Act', 'title' => 'Hit', 'for_guest' => 2, 'love' => 20]));
    expect($v->accepted()[0]['reason'])->toBe('because Jake loves Dark');
});

test('an artist already in the set or already played is rejected', function () {
    $v = djValidator(used: ['Mariza']);

    $v->add(djTrack());
    $v->add(djTrack(['title' => 'Shine On Rainy Day']));
    $v->add(djTrack(['artist' => 'Mariza', 'title' => 'Hit', 'love' => 11]));

    expect($v->log())->toBe(['ok', 'duplicate_artist', 'duplicate_artist']);
});

test('an artist a guest typed is not a discovery', function () {
    $v = djValidator(typed: ['  kate BUSH ']);

    $v->add(djTrack(['artist' => 'Kate Bush', 'title' => 'Hit', 'for_guest' => 2, 'love' => 20]));
    expect($v->log())->toBe(['typed_by_guest']);
});

test('slots are kept for guests with pools who have no track, but not for guests without pools', function () {
    $v = djValidator(target: 2);

    expect($v->add(djTrack())['accepted'])->toBeTrue();
    expect($v->add(djTrack(['artist' => 'Tems', 'title' => 'Hit']))['error'])->toContain('Jake (guest 2)')->not->toContain('Gran');
    expect($v->add(djTrack(['artist' => 'Kate Bush', 'title' => 'Hit', 'for_guest' => 2, 'love' => 20]))['accepted'])->toBeTrue();

    expect($v->log())->toBe(['ok', 'reserve_slots', 'ok'])
        ->and($v->complete())->toBeTrue()
        ->and($v->unservedIds())->toBe([]);
});

test('each guest gets at most ceil(target / guests with pools) + 1 tracks', function () {
    $v = djValidator(target: 2);
    expect($v->guestCap())->toBe(2);

    $v = djValidator(target: 10);
    expect($v->guestCap())->toBe(6);
});

test('energy and bpm must be in range', function (int $energy, int $bpm) {
    $v = djValidator();

    $v->add(djTrack(['energy' => $energy, 'bpm' => $bpm]));
    expect($v->log())->toBe(['bad_range']);
})->with([[0, 100], [6, 100], [3, 39], [3, 221]]);

test('a full set takes no more tracks', function () {
    $v = djValidator(target: 2);
    $v->add(djTrack());
    $v->add(djTrack(['artist' => 'Kate Bush', 'title' => 'Hit', 'for_guest' => 2, 'love' => 20]));

    expect($v->add(djTrack(['artist' => 'Tems', 'title' => 'Hit']))['error'])->toContain('full');
    expect($v->log())->toBe(['ok', 'ok', 'set_full']);
});

test('the target is never below the number of guests with pools', function () {
    expect(djValidator(target: 1)->target)->toBe(2);
});

test('the video check can swap in the artist\'s next song or reject the artist', function () {
    $seen = [];
    $v = djValidator(video: function (string $artist, string $title, array $others) use (&$seen) {
        $seen[] = [$artist, $title, $others];

        return $artist === 'Brent Cobb' ? $others[0] : null;
    });

    expect($v->add(djTrack())['title'])->toBe('Shine On Rainy Day');
    expect($v->add(djTrack(['artist' => 'Kate Bush', 'title' => 'Hit', 'for_guest' => 2, 'love' => 20]))['error'])->toContain('No playable video');

    expect($seen[0])->toBe(['Brent Cobb', 'Down Home', ['Shine On Rainy Day']])
        ->and($v->accepted()[0]['title'])->toBe('Shine On Rainy Day')
        ->and($v->log())->toBe(['ok', 'no_video']);
});

test('an injected instruction cannot add an off-list track', function () {
    $v = djValidator();

    foreach ([
        djTrack(['artist' => 'Rick Astley', 'title' => 'Never Gonna Give You Up']),
        djTrack(['artist' => 'Rick Astley', 'title' => 'Never Gonna Give You Up', 'love' => 999]),
        djTrack(['artist' => 'Brent Cobb', 'title' => 'Never Gonna Give You Up']),
    ] as $attempt) {
        expect($v->add($attempt)['accepted'])->toBeFalse();
    }

    expect($v->accepted())->toBe([]);
});
