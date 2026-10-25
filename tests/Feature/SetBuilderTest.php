<?php

use App\Ai\Agents\DjAgent;
use App\Enums\LoveStatus;
use App\Enums\SetItemStatus;
use App\Jobs\BuildSet;
use App\Jobs\ResolveGuest;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestLove;
use App\Models\Track;
use App\Services\Dj\GuestResolver;
use App\Services\Dj\SetBuilder;
use App\Services\Dj\SetValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.youtube.key' => 'yt-key', 'services.youtube.url' => 'https://yt.test']);
    Cache::flush();

    // YouTube: every search finds "{q}" as an official upload, every video plays.
    Http::fake(function (HttpRequest $request) {
        if (str_contains($request->url(), 'yt.test/search')) {
            return Http::response(['items' => [['id' => ['videoId' => 'v-'.md5($request['q'])], 'snippet' => ['title' => $request['q'], 'channelTitle' => 'x - Topic']]]]);
        }

        return Http::response(['items' => array_map(fn ($id) => ['id' => $id, 'status' => ['embeddable' => true, 'privacyStatus' => 'public', 'uploadStatus' => 'processed']], explode(',', $request['id']))]);
    });

    $this->event = Event::create(['code' => 'PARTY1', 'name' => 'Rooftop', 'host_token' => 'secret']);
    $this->maria = djGuest($this->event, 'Maria', ['Ozark' => ['Brent Cobb', 'Tems'], 'Lisbon' => ['Mariza']]);
    $this->jake = djGuest($this->event, 'Jake', ['Dark' => ['Kate Bush', 'Tems']]);
    $this->gran = djGuest($this->event, 'Gran', ['qxzv' => null]);

    // Capture each DjAgent so a fake model can call the real add_track tool.
    $this->agents = [];
    $this->app->resolving(DjAgent::class, fn (DjAgent $agent) => $this->agents[] = $agent);
});

/** A guest whose loves map to pools of these artists (null: unresolved). Each artist has songs "{artist} Hit" and "{artist} B-side". */
function djGuest(Event $event, string $name, array $loves): Guest
{
    $guest = $event->guests()->create(['name' => $name, 'color' => '#ffffff']);

    foreach ($loves as $text => $artists) {
        $guest->loves()->create([
            'raw_text' => $text,
            'status' => $artists === null ? LoveStatus::Unresolved : LoveStatus::Resolved,
            'pool' => $artists === null ? null : array_map(fn (string $a, int $i) => [
                'id' => 'q-'.$a, 'name' => $a, 'songs' => ["{$a} Hit", "{$a} B-side"], 'rank' => $i + 1,
            ], $artists, array_keys($artists)),
        ]);
    }

    return $guest;
}

function loveId(Guest $guest, string $text): int
{
    return GuestLove::where('guest_id', $guest->id)->where('raw_text', $text)->sole()->id;
}

/** What the fake model does on each run: a list of add_track calls. */
function fakeDj(object $test, array $runs): void
{
    $run = 0;
    DjAgent::fake(function () use ($test, &$run, $runs) {
        foreach ($runs[$run++] ?? [] as $call) {
            end($test->agents)->tools()[0]->handle(new Request(['energy' => 3, 'bpm' => 110, ...$call]));
        }

        return 'done';
    });
}

test('the set is built from add_track calls, with code-written reasons, and the first track starts playing', function () {
    fakeDj($this, [[
        ['artist' => 'Brent Cobb', 'title' => 'Brent Cobb Hit', 'for_guest' => $this->maria->id, 'love' => loveId($this->maria, 'Ozark'), 'energy' => 2, 'bpm' => 95],
        ['artist' => 'Tems', 'title' => 'Tems Hit', 'for_guest' => $this->jake->id, 'love' => loveId($this->maria, 'Ozark')],
        ['artist' => 'Rick Astley', 'title' => 'Never Gonna Give You Up', 'for_guest' => $this->maria->id, 'love' => loveId($this->maria, 'Ozark')],
    ]]);

    $result = app(SetBuilder::class)->build($this->event);

    $items = $this->event->setItems()->with('track')->orderBy('position')->get();
    $brent = $items->first(fn ($i) => $i->track->artist === 'Brent Cobb');
    $tems = $items->first(fn ($i) => $i->track->artist === 'Tems');

    // Short of the 10-track target, so the agent is nudged three times; the fake adds nothing more.
    expect($result)->toMatchArray(['tracks' => 2, 'target' => 10, 'unserved' => [], 'nudges' => 3])
        ->and($result['log'])->toBe(['ok', 'ok', 'not_in_results'])
        ->and($items)->toHaveCount(2)
        // The default arc starts at energy 3, so Tems (3) opens and Brent Cobb (2) follows.
        ->and($items[0]->is($tems))->toBeTrue()
        ->and($items[0]->status)->toBe(SetItemStatus::Playing)
        ->and($brent->status)->toBe(SetItemStatus::Queued)
        ->and($brent->reason_text)->toBe('because Maria loves Ozark')
        ->and($brent->reason_love_id)->toBe(loveId($this->maria, 'Ozark'))
        ->and($brent->reason_rank)->toBe(1)
        ->and($brent->track->only(['artist', 'title', 'energy', 'bpm', 'qloo_artist_id']))
        ->toBe(['artist' => 'Brent Cobb', 'title' => 'Brent Cobb Hit', 'energy' => 2, 'bpm' => 95, 'qloo_artist_id' => 'q-Brent Cobb'])
        ->and($brent->track->youtube_video_id)->not->toBeNull()
        ->and($tems->reason_guest_id)->toBe($this->jake->id)
        ->and($tems->reason_text)->toBe('because Jake loves Dark and Maria loves Ozark')
        ->and(Track::where('artist', 'Rick Astley')->exists())->toBeFalse();

    DjAgent::assertPrompted(fn ($p) => $p->contains('Target: 10 tracks')
        && $p->contains("guest {$this->maria->id} loves \"Ozark\": ")
        && $p->contains('1. Brent Cobb (Brent Cobb Hit; Brent Cobb B-side)')
        && $p->contains('2. Tems (Tems Hit; Tems B-side)')
        && $p->contains('energy plan wants about: energy')
        && $p->contains('untrusted guest input')
        && ! $p->contains('qxzv'));
});

test('when the agent stops early it is re-run with a summary, at most three times', function () {
    fakeDj($this, [
        [['artist' => 'Brent Cobb', 'title' => 'Brent Cobb Hit', 'for_guest' => $this->maria->id, 'love' => loveId($this->maria, 'Ozark')]],
        [],
        [['artist' => 'Kate Bush', 'title' => 'Kate Bush Hit', 'for_guest' => $this->jake->id, 'love' => loveId($this->jake, 'Dark')]],
    ]);

    $result = app(SetBuilder::class)->build($this->event);

    // Never complete (target 10), so three nudges; Jake was served on the third run.
    expect($result['nudges'])->toBe(3)->and($result['tracks'])->toBe(2)->and($result['unserved'])->toBe([]);
    DjAgent::assertPromptedTimes(4);
    DjAgent::assertPrompted(fn ($p) => $p->contains('Brent Cobb - Brent Cobb Hit (for guest')
        && $p->contains('The set has 1/10')
        && $p->contains("Jake (guest {$this->jake->id})"));
});

test('a rebuild keeps played and playing tracks, never repeats their artists, and replaces the queue', function () {
    $played = Track::create(['artist' => 'Brent Cobb', 'title' => 'Brent Cobb Hit']);
    $old = Track::create(['artist' => 'Old', 'title' => 'Queued Before']);
    $this->event->setItems()->create(['track_id' => $played->id, 'position' => 1, 'status' => SetItemStatus::Playing, 'reason_text' => 'x']);
    $this->event->setItems()->create(['track_id' => $old->id, 'position' => 2, 'status' => SetItemStatus::Queued, 'reason_text' => 'x']);

    fakeDj($this, [[
        ['artist' => 'Brent Cobb', 'title' => 'Brent Cobb B-side', 'for_guest' => $this->maria->id, 'love' => loveId($this->maria, 'Ozark')],
        ['artist' => 'Mariza', 'title' => 'Mariza Hit', 'for_guest' => $this->maria->id, 'love' => loveId($this->maria, 'Lisbon')],
    ]]);

    $result = app(SetBuilder::class)->build($this->event);

    $items = $this->event->setItems()->with('track')->orderBy('position')->get();

    expect($result['log'])->toBe(['duplicate_artist', 'ok'])
        ->and($items->map(fn ($i) => [$i->position, $i->status, $i->track->artist])->all())->toBe([
            [1, SetItemStatus::Playing, 'Brent Cobb'],
            [2, SetItemStatus::Queued, 'Mariza'],
        ]);
    DjAgent::assertPrompted(fn ($p) => $p->contains('Already played or coming up, do not repeat: Brent Cobb'));
});

test('a party where nobody could be read builds nothing and never calls the model', function () {
    GuestLove::query()->update(['status' => LoveStatus::Unresolved, 'pool' => null]);
    DjAgent::fake();

    expect(app(SetBuilder::class)->build($this->event)['tracks'])->toBe(0);
    DjAgent::assertNeverPrompted();
});

test('when the YouTube quota is used up, tracks are still accepted', function () {
    Cache::put('youtube:quota-out', true, now()->addHour());
    fakeDj($this, [[['artist' => 'Brent Cobb', 'title' => 'Brent Cobb Hit', 'for_guest' => $this->maria->id, 'love' => loveId($this->maria, 'Ozark')]]]);

    expect(app(SetBuilder::class)->build($this->event)['log'])->toBe(['ok']);
    expect(Track::where('artist', 'Brent Cobb')->sole()->youtube_video_id)->toBeNull();
    expect($this->event->setItems()->where('status', SetItemStatus::Playing)->exists())->toBeFalse();
});

test('set target is about 1.2 tracks per guest with results, between 10 and 40', function () {
    expect(SetBuilder::targetFor(1))->toBe(10)
        ->and(SetBuilder::targetFor(20))->toBe(24)
        ->and(SetBuilder::targetFor(50))->toBe(40);
});

test('the room\'s first set builds at once; later builds wait so guests joining together share one', function () {
    Queue::fake();

    (new ResolveGuest($this->gran))->handle(app(GuestResolver::class));
    Queue::assertPushed(BuildSet::class, fn (BuildSet $job) => $job->event->is($this->event) && $job->delay === null);

    $this->event->setItems()->create(['track_id' => Track::create(['artist' => 'A', 'title' => 'T'])->id, 'position' => 1, 'status' => SetItemStatus::Queued, 'reason_text' => '']);
    Queue::fake();

    (new ResolveGuest($this->gran))->handle(app(GuestResolver::class));
    Queue::assertPushed(BuildSet::class, fn (BuildSet $job) => $job->event->is($this->event) && $job->delay !== null);
    expect((new BuildSet($this->event))->uniqueId())->toBe((string) $this->event->id);
});

test('videos are found after the agent picks: a track with none in its first two songs is dropped, a known miss is refused at once', function () {
    // Mariza has no video for either song; Kate Bush's songs are already known to have none. A fresh HTTP factory:
    // fakes stack, and the one from beforeEach would answer first.
    Http::swap(new HttpFactory);
    Http::fake(function (HttpRequest $request) {
        if (str_contains($request->url(), 'yt.test/search')) {
            return Http::response(['items' => str_contains($request['q'], 'Mariza') ? [] : [
                ['id' => ['videoId' => 'v-'.md5($request['q'])], 'snippet' => ['title' => $request['q'], 'channelTitle' => 'x - Topic']],
            ]]);
        }

        return Http::response(['items' => array_map(fn ($id) => ['id' => $id, 'status' => ['embeddable' => true, 'privacyStatus' => 'public', 'uploadStatus' => 'processed']], explode(',', $request['id']))]);
    });
    foreach (['Kate Bush Hit', 'Kate Bush B-side'] as $title) {
        Track::create(['artist' => 'Kate Bush', 'title' => $title, 'youtube_checked_at' => now()]);
    }

    fakeDj($this, [[
        ['artist' => 'Brent Cobb', 'title' => 'Brent Cobb Hit', 'for_guest' => $this->maria->id, 'love' => loveId($this->maria, 'Ozark')],
        ['artist' => 'Mariza', 'title' => 'Mariza Hit', 'for_guest' => $this->maria->id, 'love' => loveId($this->maria, 'Lisbon')],
        ['artist' => 'Kate Bush', 'title' => 'Kate Bush Hit', 'for_guest' => $this->jake->id, 'love' => loveId($this->jake, 'Dark')],
    ]]);

    $result = app(SetBuilder::class)->build($this->event);

    expect($result['log'])->toBe(['ok', 'ok', 'no_video'])
        ->and($result['tracks'])->toBe(1)
        ->and($result['unserved'])->toBe([$this->jake->id])
        ->and($result['youtube_lookups'])->toBe(2)
        ->and($this->event->setItems()->with('track')->get()->map(fn ($i) => $i->track->artist)->all())->toBe(['Brent Cobb'])
        ->and(Track::where('artist', 'Mariza')->whereNotNull('youtube_checked_at')->count())->toBe(2);
});

test('a guest\'s first track comes from the middle ground of their loves, and is not tied to one love', function () {
    $this->maria->update(['blend' => ['loves' => [], 'text' => 'Ozark + Lisbon', 'pool' => [
        ['id' => 'q-Cesaria', 'name' => 'Cesaria', 'songs' => ['Cesaria Hit', 'Cesaria B-side'], 'genres' => [], 'rank' => 1],
    ]]]);
    $blend = SetValidator::BLEND_BASE + $this->maria->id;

    fakeDj($this, [[
        ['artist' => 'Brent Cobb', 'title' => 'Brent Cobb Hit', 'for_guest' => $this->maria->id, 'love' => loveId($this->maria, 'Ozark')],
        ['artist' => 'Cesaria', 'title' => 'Cesaria Hit', 'for_guest' => $this->maria->id, 'love' => $blend],
        ['artist' => 'Brent Cobb', 'title' => 'Brent Cobb Hit', 'for_guest' => $this->maria->id, 'love' => loveId($this->maria, 'Ozark')],
    ]]);

    $result = app(SetBuilder::class)->build($this->event);

    $item = $this->event->setItems()->with('track')->get()->first(fn ($i) => $i->track->artist === 'Cesaria');

    expect($result['log'])->toBe(['blend_first', 'ok', 'ok'])
        ->and($item->reason_text)->toBe('because Maria loves Ozark + Lisbon')
        // A blend is not a guest_loves row.
        ->and($item->reason_love_id)->toBeNull()
        ->and($item->reason_guest_id)->toBe($this->maria->id);
    DjAgent::assertPrompted(fn ($p) => $p->contains('MIDDLE GROUND') && $p->contains("Love {$blend}"));
});

test('the occasion and the energy plan are in the prompt, so a different party gets different songs', function () {
    $this->event->update(['occasion' => 'workout', 'energy_plan' => ['timeline' => 'trip', 'minutes' => 60, 'points' => [['at' => 0, 'energy' => 5], ['at' => 60, 'energy' => 5]]]]);
    fakeDj($this, [[]]);

    app(SetBuilder::class)->build($this->event);

    DjAgent::assertPrompted(fn ($p) => $p->contains('Party: Rooftop (workout)') && $p->contains('energy 5: 10'));
});

test('a track that could not be looked up is dropped while others can play, and kept when none can', function () {
    // YouTube's search is paused: Brent Cobb has a stored video, Mariza has none and cannot be looked up.
    Http::swap(new HttpFactory);
    Http::fake(['yt.test/search*' => Http::response(['error' => ['message' => 'quota', 'errors' => [['reason' => 'quotaExceeded']]]], 403)]);
    Track::create(['artist' => 'Brent Cobb', 'title' => 'Brent Cobb Hit', 'youtube_video_id' => 'stored1', 'youtube_checked_at' => now()]);

    fakeDj($this, [[
        ['artist' => 'Brent Cobb', 'title' => 'Brent Cobb Hit', 'for_guest' => $this->maria->id, 'love' => loveId($this->maria, 'Ozark')],
        ['artist' => 'Mariza', 'title' => 'Mariza Hit', 'for_guest' => $this->maria->id, 'love' => loveId($this->maria, 'Lisbon')],
    ]]);
    app(SetBuilder::class)->build($this->event);

    expect($this->event->setItems()->with('track')->get()->map(fn ($i) => $i->track->artist)->all())->toBe(['Brent Cobb']);

    // Nothing playable anywhere: the set is kept so the screen has something to show.
    $this->event->setItems()->delete();
    Track::query()->delete();
    fakeDj($this, [[
        ['artist' => 'Mariza', 'title' => 'Mariza Hit', 'for_guest' => $this->maria->id, 'love' => loveId($this->maria, 'Lisbon')],
    ]]);
    app(SetBuilder::class)->build($this->event);

    expect($this->event->setItems()->with('track')->get()->map(fn ($i) => $i->track->artist)->all())->toBe(['Mariza']);
});

test('a song of an artist that already has a stored video is used, so no search is spent', function () {
    Track::create(['artist' => 'Brent Cobb', 'title' => 'Brent Cobb B-side', 'youtube_video_id' => 'stored2', 'youtube_checked_at' => now()]);

    fakeDj($this, [[
        ['artist' => 'Brent Cobb', 'title' => 'Brent Cobb Hit', 'for_guest' => $this->maria->id, 'love' => loveId($this->maria, 'Ozark')],
    ]]);
    app(SetBuilder::class)->build($this->event);

    expect($this->event->setItems()->with('track')->get()->map(fn ($i) => $i->track->title)->all())->toBe(['Brent Cobb B-side']);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/search'));
});

test('a guest whose blend gives nothing is served from their own loves after a few rounds', function () {
    // The blend's only artist is already in the set for someone else, so it can never be placed.
    $this->maria->update(['blend' => ['loves' => [], 'text' => 'Ozark + Lisbon', 'pool' => [
        ['id' => 'q-Tems', 'name' => 'Tems', 'songs' => ['Tems Hit'], 'genres' => [], 'rank' => 1],
    ]]]);
    $blend = SetValidator::BLEND_BASE + $this->maria->id;
    $ozark = loveId($this->maria, 'Ozark');
    $jake = ['artist' => 'Tems', 'title' => 'Tems Hit', 'for_guest' => $this->jake->id, 'love' => loveId($this->jake, 'Dark')];
    $own = ['artist' => 'Brent Cobb', 'title' => 'Brent Cobb Hit', 'for_guest' => $this->maria->id, 'love' => $ozark];

    fakeDj($this, [[$jake, $own], [$own], [$own], [$own]]);
    $result = app(SetBuilder::class)->build($this->event);

    expect($result['unserved'])->toBe([])
        ->and($this->event->setItems()->with('track')->get()->pluck('track.artist')->sort()->values()->all())->toBe(['Brent Cobb', 'Tems'])
        ->and($result['log'])->toContain('blend_first');
});

test('the plan decides what Qloo is asked: calm and energetic lists for the low and high slots', function () {
    config(['services.qloo.key' => 'test-key', 'services.qloo.url' => 'https://qloo.test']);
    $this->maria->loves()->where('raw_text', 'Ozark')->update(['qloo_entity_id' => 'tv1', 'qloo_type' => 'tv_show', 'resolved_name' => 'Ozark']);
    $this->event->update(['energy_plan' => ['timeline' => 'trip', 'minutes' => 60, 'points' => [['at' => 0, 'energy' => 1], ['at' => 15, 'energy' => 5]]]]);

    Http::swap(new HttpFactory);
    Http::fake(function (HttpRequest $request) {
        $url = urldecode($request->url());

        if (str_contains($url, 'qloo.test')) {
            $name = str_contains($url, 'style:qloo:calm') ? 'Calmo' : 'Energo';

            return Http::response(['results' => ['entities' => [['entity_id' => "q-{$name}", 'name' => $name, 'properties' => ['notable_songs' => ["{$name} Hit"]],
                'tags' => [['id' => 'g', 'name' => 'Fado', 'type' => 'urn:tag:genre:qloo']]]]]]);
        }

        return Http::response(['items' => []]);
    });
    fakeDj($this, [[]]);

    app(SetBuilder::class)->build($this->event);

    DjAgent::assertPrompted(fn ($p) => $p->contains('CALM list for "Ozark (calm)": 1. Calmo')
        && $p->contains('ENERGETIC list for "Ozark (energetic)": 1. Energo')
        && $p->contains('fill slots wanting energy 1-2 from CALM lists'));
    Http::assertSent(fn (HttpRequest $r) => str_contains(urldecode($r->url()), 'signal.interests.entities=tv1') && str_contains(urldecode($r->url()), 'filter.tags=urn:tag:style:qloo:calm'));
    // Guests with no Qloo signal (Jake, Gran) cost no call: two moods, one guest.
    Http::assertSentCount(2);
});

test('a plan with no low or high slots asks Qloo nothing extra', function () {
    $this->maria->loves()->where('raw_text', 'Ozark')->update(['qloo_entity_id' => 'tv1', 'qloo_type' => 'tv_show']);
    $this->event->update(['energy_plan' => ['timeline' => 'trip', 'minutes' => 60, 'points' => [['at' => 0, 'energy' => 3], ['at' => 60, 'energy' => 3]]]]);
    fakeDj($this, [[]]);

    app(SetBuilder::class)->build($this->event);

    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'qloo'));
});

test('a track remembers its artist\'s genres, and a calm-list pick is not tied to the guest\'s other loves', function () {
    $this->maria->update(['blend' => null]);
    $calm = SetValidator::CALM_BASE + $this->maria->id;
    $validator = new SetValidator(
        guests: [$this->maria->id => 'Maria'],
        loves: [
            $calm => ['id' => $calm, 'guest_id' => $this->maria->id, 'text' => 'Ozark (calm)', 'mood' => 'calm', 'pool' => [['id' => 'q1', 'name' => 'Calmo', 'songs' => ['Calmo Hit'], 'genres' => ['Fado'], 'rank' => 1]]],
            1 => ['id' => 1, 'guest_id' => $this->maria->id, 'text' => 'Ozark', 'pool' => [['id' => 'q2', 'name' => 'Other', 'songs' => ['Other Hit'], 'rank' => 1]]],
        ],
        typed: [],
        target: 10,
    );

    expect($validator->add(['artist' => 'Calmo', 'title' => 'Calmo Hit', 'for_guest' => $this->maria->id, 'love' => $calm, 'energy' => 2, 'bpm' => 80])['accepted'])->toBeTrue()
        ->and($validator->accepted()[0]['genres'])->toBe(['Fado'])
        ->and($validator->accepted()[0]['reason'])->toBe('because Maria loves Ozark (calm)');
});
