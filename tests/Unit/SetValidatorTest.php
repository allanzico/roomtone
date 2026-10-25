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

    // Tems is only in Maria's results (her Lisbon gets a track first: loves take turns).
    $v->add(djTrack(['artist' => 'Mariza', 'title' => 'Hit', 'love' => 11]));
    $v->add(djTrack(['artist' => 'Tems', 'title' => 'Hit', 'love' => 10]));
    expect($v->accepted()[2])->toMatchArray(['reason' => 'because Maria loves Ozark', 'bridge' => false]);
});

test('a guest\'s loves take turns: one love gets a second track only after the others have one', function () {
    $v = djValidator();

    $v->add(djTrack(['artist' => 'Brent Cobb', 'love' => 10]));
    $refused = $v->add(djTrack(['artist' => 'Tems', 'title' => 'Hit', 'love' => 10]));

    expect($refused['error'])->toContain('Use their other loves first: love 11 (Lisbon)')
        ->and($v->add(djTrack(['artist' => 'Mariza', 'title' => 'Hit', 'love' => 11]))['accepted'])->toBeTrue()
        ->and($v->add(djTrack(['artist' => 'Tems', 'title' => 'Hit', 'love' => 10]))['accepted'])->toBeTrue()
        ->and($v->log())->toBe(['ok', 'love_spread', 'ok', 'ok']);
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

test('a peak plan reserves most slots for energetic Qloo results', function () {
    $entry = fn (string $name) => ['id' => $name, 'name' => $name, 'songs' => ['Hit'], 'rank' => 1];
    $v = new SetValidator(
        guests: [1 => 'Guest'],
        loves: [
            1 => ['id' => 1, 'guest_id' => 1, 'text' => 'Amsterdam', 'pool' => [$entry('Ordinary'), $entry('Another')]],
            SetValidator::BLEND_BASE + 1 => ['id' => SetValidator::BLEND_BASE + 1, 'guest_id' => 1, 'text' => 'all loves', 'blend' => true, 'pool' => [$entry('Blend')]],
            SetValidator::ENERGETIC_BASE + 1 => ['id' => SetValidator::ENERGETIC_BASE + 1, 'guest_id' => 1, 'text' => 'energetic', 'mood' => 'energetic',
                'pool' => array_map($entry, ['Dance A', 'Dance B', 'Dance C', 'Dance D'])],
        ],
        typed: [], target: 5, energyTargets: [5, 5, 5, 5, 5],
    );
    $add = fn (string $artist, int $love, int $energy) => $v->add([
        'artist' => $artist, 'title' => 'Hit', 'for_guest' => 1, 'love' => $love, 'energy' => $energy, 'bpm' => 125,
    ]);

    expect($add('Blend', SetValidator::BLEND_BASE + 1, 5)['accepted'])->toBeTrue();
    expect($add('Ordinary', 1, 5)['accepted'])->toBeFalse();
    expect($v->log())->toBe(['ok', 'mood_quota']);
    expect($add('Dance A', SetValidator::ENERGETIC_BASE + 1, 2)['accepted'])->toBeFalse();
    expect($v->log()[2])->toBe('mood_energy');
    foreach (['Dance A', 'Dance B', 'Dance C', 'Dance D'] as $artist) {
        expect($add($artist, SetValidator::ENERGETIC_BASE + 1, 5)['accepted'])->toBeTrue();
    }
    expect($v->complete())->toBeTrue();
});

test('a matching mood pick can serve a guest before their blend', function () {
    $entry = fn (string $name) => ['id' => $name, 'name' => $name, 'songs' => ['Hit'], 'rank' => 1];
    $v = new SetValidator(
        guests: [1 => 'Guest'],
        loves: [
            SetValidator::BLEND_BASE + 1 => ['id' => SetValidator::BLEND_BASE + 1, 'guest_id' => 1, 'text' => 'all loves', 'blend' => true, 'pool' => [$entry('Blend')]],
            SetValidator::ENERGETIC_BASE + 1 => ['id' => SetValidator::ENERGETIC_BASE + 1, 'guest_id' => 1, 'text' => 'energetic', 'mood' => 'energetic', 'pool' => [$entry('Dance')]],
        ],
        typed: [], target: 2, energyTargets: [5, 5],
    );

    expect($v->add(['artist' => 'Dance', 'title' => 'Hit', 'for_guest' => 1, 'love' => SetValidator::ENERGETIC_BASE + 1, 'energy' => 5, 'bpm' => 125])['accepted'])->toBeTrue();
});

test('a reused recording keeps its measured energy across different plans', function () {
    $entry = ['id' => 'a', 'name' => 'Known Act', 'songs' => ['Slow Song'], 'rank' => 1];
    $loves = [1 => ['id' => 1, 'guest_id' => 1, 'text' => 'A show', 'pool' => [$entry]],
        SetValidator::ENERGETIC_BASE + 1 => ['id' => SetValidator::ENERGETIC_BASE + 1, 'guest_id' => 1,
            'text' => 'energetic', 'mood' => 'energetic', 'pool' => [$entry]]];
    $v = new SetValidator(
        guests: [1 => 'Guest'], loves: $loves, typed: [], target: 2, energyTargets: [5, 5],
        storedMeasurements: fn () => ['energy' => 2, 'bpm' => 85],
    );
    $args = ['artist' => 'Known Act', 'title' => 'Slow Song', 'for_guest' => 1, 'energy' => 5, 'bpm' => 140];

    expect($v->add([...$args, 'love' => SetValidator::ENERGETIC_BASE + 1])['accepted'])->toBeFalse();
    expect($v->log())->toBe(['mood_energy']);
    expect($v->add([...$args, 'love' => 1])['accepted'])->toBeTrue();
    expect($v->accepted()[0])->toMatchArray(['energy' => 2, 'bpm' => 85]);
});

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
