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
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        && $p->contains("guest {$this->maria->id} loves \"Ozark\": 1. Brent Cobb (Brent Cobb Hit; Brent Cobb B-side)")
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
