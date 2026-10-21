<?php

use App\Services\Dj\RequestValidator;

function reqValidator(int $target = 2, array $used = [], ?Closure $video = null): RequestValidator
{
    return new RequestValidator(
        guests: [1 => 'Maria', 2 => 'Jake'],
        pools: [
            1 => [['id' => 'a1', 'name' => 'Burna Boy', 'songs' => ['Last Last', 'Ye'], 'rank' => 1], ['id' => 'a2', 'name' => 'Tems', 'songs' => ['Free Mind'], 'rank' => 2]],
            2 => [['id' => 'a3', 'name' => 'Wizkid', 'songs' => ['Essence'], 'rank' => 1], ['id' => 'a1', 'name' => 'Burna Boy', 'songs' => ['Last Last'], 'rank' => 4]],
        ],
        typed: ['Tems'],
        target: $target,
        summary: 'more afrobeats',
        usedArtists: $used,
        playableTitle: $video,
    );
}

function reqTrack(array $overrides = []): array
{
    return ['artist' => 'Burna Boy', 'title' => 'Last Last', 'for_guest' => 1, 'energy' => 4, 'bpm' => 110, ...$overrides];
}

test('a track from the guest\'s filtered results is accepted with a code-written reason', function () {
    $v = reqValidator();

    expect($v->add(reqTrack())['accepted'])->toBeTrue();
    expect($v->accepted()[0])->toMatchArray([
        'artist' => 'Burna Boy', 'title' => 'Last Last', 'for_guest_id' => 1, 'rank' => 1,
        'reason' => "for Maria's taste, as the host asked: more afrobeats",
    ]);
});

test('every rule rejects with its code', function (array $calls, array $log) {
    $v = reqValidator(used: ['Wizkid']);

    foreach ($calls as $call) {
        $v->add(reqTrack($call));
    }

    expect($v->log())->toBe($log);
})->with([
    'bad args' => [[['for_guest' => 'Maria']], ['bad_args']],
    'unknown guest' => [[['for_guest' => 9]], ['not_stored']],
    'not in that guest\'s results' => [[['artist' => 'Rick Astley']], ['not_in_results']],
    'title not notable' => [[['title' => 'Made Up']], ['title_not_notable']],
    'one per guest' => [[[], ['artist' => 'Tems', 'title' => 'Free Mind']], ['ok', 'guest_done']],
    'already in the set' => [[['artist' => 'Wizkid', 'title' => 'Essence', 'for_guest' => 2]], ['duplicate_artist']],
    'already picked' => [[[], ['for_guest' => 2]], ['ok', 'duplicate_artist']],
    'typed by a guest' => [[['artist' => 'Tems', 'title' => 'Free Mind']], ['typed_by_guest']],
    'range' => [[['energy' => 9]], ['bad_range']],
]);

test('it stops at the target', function () {
    $v = reqValidator(target: 1);
    $v->add(reqTrack());

    expect($v->add(reqTrack(['artist' => 'Burna Boy', 'for_guest' => 2]))['error'])->toContain('enough');
    expect($v->log())->toBe(['ok', 'set_full']);
});

test('the video check can swap the title or reject the artist', function () {
    $v = reqValidator(video: fn (string $artist, string $title, array $others) => $artist === 'Burna Boy' ? $others[0] : null);

    $v->add(reqTrack());
    $v->add(reqTrack(['artist' => 'Wizkid', 'title' => 'Essence', 'for_guest' => 2]));

    expect($v->accepted()[0]['title'])->toBe('Ye')->and($v->log())->toBe(['ok', 'no_video']);
});
