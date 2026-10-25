<?php

use App\Enums\SetItemStatus;
use App\Jobs\ResolveSetVideos;
use App\Models\Event;
use App\Models\Track;
use App\Services\YouTube\TrackResolver;
use App\Services\YouTube\YouTubeClient;
use App\Services\YouTube\YouTubeException;
use Database\Seeders\DemoPartySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.youtube.key' => 'yt-key', 'services.youtube.url' => 'https://yt.test']);
    Cache::flush();
});

function video(string $id, string $title, string $channel): array
{
    return ['id' => ['kind' => 'youtube#video', 'videoId' => $id], 'snippet' => ['title' => $title, 'channelTitle' => $channel]];
}

function status(string $id, bool $embeddable = true, string $privacy = 'public', int $views = 1000, string $duration = 'PT3M30S'): array
{
    return [
        'id' => $id,
        'status' => ['embeddable' => $embeddable, 'privacyStatus' => $privacy, 'uploadStatus' => 'processed'],
        'statistics' => ['viewCount' => (string) $views],
        'contentDetails' => ['duration' => $duration],
    ];
}

/** Fake YouTube: search answers by song title, videos.list answers every id as playable unless listed in $blocked. */
function fakeYouTube(array $searches, array $blocked = [], array $views = [], array $durations = []): void
{
    Http::fake(function (Request $request) use ($searches, $blocked, $views, $durations) {
        if (str_contains($request->url(), 'yt.test/search')) {
            foreach ($searches as $needle => $items) {
                if (str_contains($request['q'], $needle)) {
                    return Http::response(['items' => $items]);
                }
            }

            return Http::response(['items' => []]);
        }

        return Http::response(['items' => array_map(
            fn (string $id) => status($id, embeddable: ! in_array($id, $blocked, true), views: $views[$id] ?? 1000, duration: $durations[$id] ?? 'PT3M30S'),
            explode(',', $request['id']),
        )]);
    });
}

test('search asks for embeddable music videos and decodes titles', function () {
    Http::fake(['yt.test/search*' => Http::response(['items' => [video('v1', 'Tems - Free Mind (Official Video) &amp; more', 'TemsVEVO')]])]);

    expect(app(YouTubeClient::class)->search('Tems Free Mind'))->toBe([
        ['id' => 'v1', 'title' => 'Tems - Free Mind (Official Video) & more', 'channel' => 'TemsVEVO'],
    ]);

    Http::assertSent(fn (Request $r) => $r['type'] === 'video' && $r['videoEmbeddable'] === 'true'
        && $r['key'] === 'yt-key' && $r['q'] === 'Tems Free Mind');
});

test('playable keeps public, processed, embeddable videos in the order asked', function () {
    Http::fake(['yt.test/videos*' => Http::response(['items' => [
        status('c'), status('a'), status('b', embeddable: false), status('d', privacy: 'private'),
    ]])]);

    expect(app(YouTubeClient::class)->playable(['a', 'b', 'c', 'd']))->toBe(['a', 'c']);
    Http::assertSent(fn (Request $r) => $r['id'] === 'a,b,c,d' && $r['part'] === 'status,statistics,contentDetails');
});

test('a used-up search quota stops searching until midnight Pacific time', function () {
    Http::fake(['yt.test/search*' => Http::response(['error' => [
        'message' => 'The request cannot be completed because you have exceeded your quota.',
        'errors' => [['reason' => 'quotaExceeded']],
    ]], 403)]);
    $youtube = app(YouTubeClient::class);

    expect(fn () => $youtube->search('a'))->toThrow(YouTubeException::class, 'exceeded your quota');
    expect(fn () => $youtube->search('b'))->toThrow(YouTubeException::class, 'search is paused');
    Http::assertSentCount(1);

    $this->travelTo(now('America/Los_Angeles')->addDay()->startOfDay()->addMinute());
    expect(fn () => $youtube->search('c'))->toThrow(YouTubeException::class, 'exceeded your quota');
    Http::assertSentCount(2);
});

test('the daily search bucket answering 429 also stops searching until midnight Pacific time', function () {
    Http::fake(['yt.test/search*' => Http::response(['error' => [
        'code' => 429,
        'message' => "Quota exceeded for quota metric 'Search Queries' and limit 'Search Queries per day'",
        'errors' => [['reason' => 'rateLimitExceeded']],
        'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'metadata' => ['quota_limit' => 'defaultSearchListPerDayPerProject']]],
    ]], 429)]);
    $youtube = app(YouTubeClient::class);

    expect(fn () => $youtube->search('a'))->toThrow(YouTubeException::class, '429');
    $this->travel(2)->hours();
    expect(fn () => $youtube->search('b'))->toThrow(YouTubeException::class, 'search is paused');
    Http::assertSentCount(1);
});

test('a short 429 on search pauses searching for a minute', function () {
    Http::fake(['yt.test/search*' => Http::sequence()
        ->push(['error' => ['message' => 'slow down', 'errors' => [['reason' => 'rateLimitExceeded']]]], 429)
        ->push(['items' => []]),
    ]);
    $youtube = app(YouTubeClient::class);

    expect(fn () => $youtube->search('a'))->toThrow(YouTubeException::class);
    expect(fn () => $youtube->search('b'))->toThrow(YouTubeException::class, 'search is paused');
    $this->travel(61)->seconds();
    expect($youtube->search('c'))->toBe([]);
});

test('a missing key fails loudly', function () {
    config(['services.youtube.key' => null]);
    Http::fake();

    expect(fn () => app(YouTubeClient::class)->search('a'))->toThrow(YouTubeException::class, 'YOUTUBE_API_KEY');
    Http::assertNothingSent();
});

test('the artist\'s own upload wins over covers, live takes and other channels', function () {
    fakeYouTube(['Free Mind' => [
        video('cover', 'Free Mind - Tems (cover by Ana)', 'Ana Sings'),
        video('live', 'Tems - Free Mind (Live at Glastonbury)', 'BBC Music'),
        video('fan', 'Tems Free Mind lyrics', 'LyricsWorld'),
        video('topic', 'Free Mind', 'Tems - Topic'),
    ]]);

    $track = app(TrackResolver::class)->resolve('Tems', ['Free Mind']);

    expect($track->youtube_video_id)->toBe('topic')
        ->and($track->youtube_checked_at)->not->toBeNull()
        ->and(Track::count())->toBe(1);
});

test('a song whose own title has a version word keeps it', function () {
    fakeYouTube(['Live Your Life' => [video('v1', 'T.I. - Live Your Life ft. Rihanna', 'T.I.')]]);

    expect(app(TrackResolver::class)->resolve('T.I.', ['Live Your Life'])->youtube_video_id)->toBe('v1');
});

test('accents and punctuation do not stop a match', function () {
    fakeYouTube(['Halo' => [video('v1', 'Beyonce - Halo', 'BeyonceVEVO')]]);

    expect(app(TrackResolver::class)->resolve('Beyoncé', ['Halo (Remastered)'])->youtube_video_id)->toBe('v1');
});

test('a transliterated title still matches, but a different song does not', function () {
    fakeYouTube(['Daraga' => [
        video('other', 'Tamer Hosny - Ana Baba', 'Tamer Hosny'),
        video('v1', '180 Darga - Tamer Hosny', 'Tamer Hosny'),
    ]]);

    expect(app(TrackResolver::class)->resolve('Tamer Hosny', ['180 Daraga'])->youtube_video_id)->toBe('v1');
});

test('unrelated popular videos are not taken for a missing song', function () {
    fakeYouTube(['Alone' => [video('junk', 'I met Zendaya', 'Brent Rivera'), video('junk2', 'Taylor Swift as a neighbor #shorts', 'Jazzy Skye')]]);

    expect(app(TrackResolver::class)->resolve('Katy J Pearson', ["I Don't Want To Be Alone"]))->toBeNull();
});

test('the artist named somewhere in another channel\'s title is not the artist\'s song', function () {
    fakeYouTube(['Mystic Waters' => [video('podcast', 'The Warrior Mystic – Episode 007 – Awen of Water', 'Black Mountain Druidry')]]);

    expect(app(TrackResolver::class)->resolve('Awen', ['Mystic Waters']))->toBeNull();
});

test('a fan upload named "Artist - Song" is accepted when nothing official is found', function () {
    fakeYouTube(['Free Mind' => [video('fan', 'Tems - Free Mind (Music Video)', 'guardi')]]);

    expect(app(TrackResolver::class)->resolve('Tems', ['Free Mind'])->youtube_video_id)->toBe('fan');
});

test('a title with no video falls back to the artist\'s next song, and both lookups are stored', function () {
    fakeYouTube([
        'Ghost Song' => [video('x', 'Someone Else - Other Song', 'Someone Else')],
        'Real Song' => [video('blocked', 'Tems - Real Song', 'Tems'), video('ok', 'Tems - Real Song (Audio)', 'Tems - Topic')],
    ], blocked: ['blocked']);

    $track = app(TrackResolver::class)->resolve('Tems', ['Ghost Song', 'Real Song']);

    expect($track->title)->toBe('Real Song')->and($track->youtube_video_id)->toBe('ok');

    $ghost = Track::where('title', 'Ghost Song')->sole();
    expect($ghost->youtube_video_id)->toBeNull()->and($ghost->youtube_checked_at)->not->toBeNull();
});

test('a stored lookup is never searched again, found or not', function () {
    fakeYouTube(['Real Song' => [video('ok', 'Tems - Real Song', 'Tems')]]);
    $resolver = app(TrackResolver::class);

    $resolver->resolve('Tems', ['Ghost Song', 'Real Song']);
    $searches = Http::recorded(fn (Request $r) => str_contains($r->url(), '/search'))->count();
    $again = $resolver->resolve('Tems', ['Ghost Song', 'Real Song']);

    expect($searches)->toBe(2)
        ->and($again->youtube_video_id)->toBe('ok')
        ->and(Http::recorded(fn (Request $r) => str_contains($r->url(), '/search')))->toHaveCount(2);
});

test('an artist with no playable song in the first two titles is given up on', function () {
    fakeYouTube([]);

    expect(app(TrackResolver::class)->resolve('Nobody', ['A', 'B', 'C']))->toBeNull();
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/search')))->toHaveCount(TrackResolver::MAX_TITLES);
});

test('a failed lookup is not stored as "no video"', function () {
    Http::fake(['yt.test/search*' => Http::response(['error' => ['message' => 'Backend Error']], 500)]);

    expect(fn () => app(TrackResolver::class)->resolve('Tems', ['Free Mind']))->toThrow(YouTubeException::class);
    expect(Track::count())->toBe(0);
});

test('the daily refresh keeps playable videos, drops dead ones and forgets old misses, without searching', function () {
    $old = now()->subDays(40);
    $alive = Track::create(['artist' => 'A', 'title' => 'Alive', 'youtube_video_id' => 'alive', 'youtube_checked_at' => $old]);
    $dead = Track::create(['artist' => 'A', 'title' => 'Dead', 'youtube_video_id' => 'dead', 'youtube_checked_at' => $old]);
    $miss = Track::create(['artist' => 'A', 'title' => 'Miss', 'youtube_checked_at' => $old]);
    $fresh = Track::create(['artist' => 'A', 'title' => 'Fresh', 'youtube_video_id' => 'fresh', 'youtube_checked_at' => now()->subDays(3)]);
    Http::fake(['yt.test/videos*' => Http::response(['items' => [status('alive')]])]);

    $this->artisan('roomtone:refresh-videos')->assertSuccessful();

    expect($alive->fresh()->youtube_video_id)->toBe('alive')
        ->and($alive->fresh()->youtube_checked_at->isToday())->toBeTrue()
        ->and($dead->fresh()->youtube_video_id)->toBeNull()
        ->and($dead->fresh()->youtube_checked_at)->toBeNull()
        ->and($miss->fresh()->youtube_checked_at)->toBeNull()
        ->and($fresh->fresh()->youtube_checked_at->isSameDay(now()->subDays(3)))->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r['id'] === 'alive,dead');
});

test('resolving a set finds videos once, swaps in a fallback track and skips played items', function () {
    $this->seed(DemoPartySeeder::class);
    $event = Event::query()->where('code', 'DEMO')->sole();
    $last = $event->setItems()->where('position', 1)->sole();
    $event->setItems()->where('position', 10)->update(['status' => SetItemStatus::Played]);
    fakeYouTube([
        'Burna Boy Last Last' => [],
        'Burna Boy Ye' => [video('ye', 'Burna Boy - Ye', 'Burna Boy')],
        'Whitney' => [video('whitney', 'Whitney Houston - I Wanna Dance with Somebody (Who Loves Me)', 'whitneyhoustonVEVO')],
    ]);

    ResolveSetVideos::dispatchSync($event, [$last->id => ['Ye']]);

    expect($last->fresh()->track->title)->toBe('Ye')
        ->and($last->fresh()->track->youtube_video_id)->toBe('ye')
        ->and(Track::where('title', 'Last Last')->sole()->youtube_checked_at)->not->toBeNull()
        ->and(Track::where('artist', 'Whitney Houston')->sole()->youtube_video_id)->toBe('whitney')
        ->and(Track::where('title', 'Girls Just Want to Have Fun')->sole()->youtube_checked_at)->toBeNull();
    // 9 unplayed tracks, plus one fallback search.
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/search')))->toHaveCount(10);

    ResolveSetVideos::dispatchSync($event);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/search')))->toHaveCount(10);
});

test('the resolve command reports how many tracks have a video', function () {
    $this->seed(DemoPartySeeder::class);
    fakeYouTube(['Tems' => [video('tems', 'Tems - Free Mind', 'Tems')]]);

    $this->artisan('roomtone:resolve-videos', ['code' => 'demo'])
        ->expectsOutput('1 of 10 tracks have a video.')
        ->assertSuccessful();
});

test('a whole set is looked up at once: searches side by side, one playability check per round, fallbacks in a second round', function () {
    fakeYouTube([
        'Free Mind' => [video('tems', 'Tems - Free Mind', 'Tems - Topic')],
        'Ghost Song' => [],
        'Real Song' => [video('mariza', 'Mariza - Real Song', 'Mariza')],
        'Nothing' => [],
    ]);
    Track::create(['artist' => 'Known', 'title' => 'Stored', 'youtube_video_id' => 'kept', 'youtube_checked_at' => now()]);

    $found = app(TrackResolver::class)->resolveMany([
        3 => ['Tems', ['Free Mind']],
        5 => ['Mariza', ['Ghost Song', 'Real Song']],
        7 => ['Nobody', ['Nothing']],
        9 => ['Known', ['Stored']],
    ]);

    expect($found[3]->youtube_video_id)->toBe('tems')
        ->and($found[5]->title)->toBe('Real Song')
        ->and($found[5]->youtube_video_id)->toBe('mariza')
        ->and($found[7])->toBeNull()
        ->and($found[9]->youtube_video_id)->toBe('kept');

    // Round 1: three searches and one check; round 2: Mariza's next song. The stored track is never searched.
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/search')))->toHaveCount(4)
        ->and(Http::recorded(fn (Request $r) => str_contains($r->url(), '/videos')))->toHaveCount(2)
        ->and(Track::where('title', 'Nothing')->sole()->youtube_checked_at)->not->toBeNull();
});

test('in a set lookup, a search that fails keeps the track without a video and stores nothing', function () {
    Http::fake(['yt.test/search*' => Http::response(['error' => ['message' => 'Backend Error']], 500)]);

    $found = app(TrackResolver::class)->resolveMany([1 => ['Tems', ['Free Mind']]]);

    expect($found[1]->title)->toBe('Free Mind')
        ->and($found[1]->youtube_video_id)->toBeNull()
        ->and(Track::count())->toBe(0);
});

test('the search asks for the official upload', function () {
    expect(TrackResolver::query('Tems', 'Free Mind'))->toBe('Tems Free Mind official');
});

test('talent show and stage recordings are not the song', function () {
    fakeYouTube(['Satellite' => [
        video('voice', 'Lena Meyer Landrut - Satellite (Olivia) | The Voice Kids 2013 | Blind Auditions | SAT.1', 'The Voice Kids'),
        video('idol', 'Lena - Satellite - Idol Finale', 'Idol'),
    ]]);

    expect(app(TrackResolver::class)->resolve('Lena Meyer Landrut', ['Satellite']))->toBeNull();
});

test('the artist\'s official video beats a more watched upload by someone else, and a VEVO beats a Topic upload', function () {
    fakeYouTube(['Free Mind' => [
        video('fan', 'Tems - Free Mind', 'FanChannel'),
        video('topic', 'Free Mind', 'Tems - Topic'),
        video('vevo', 'Tems - Free Mind (Official Video)', 'TemsVEVO'),
    ]], views: ['fan' => 90_000_000, 'topic' => 5_000_000, 'vevo' => 1_000_000]);

    expect(app(TrackResolver::class)->resolve('Tems', ['Free Mind'])->youtube_video_id)->toBe('vevo');
});

test('among equals, "official" in the title wins', function () {
    fakeYouTube(['Free Mind' => [
        video('a', 'Tems - Free Mind', 'Fan A'),
        video('b', 'Tems - Free Mind', 'Fan B'),
        video('c', 'Tems - Free Mind (Official Audio)', 'Fan C'),
    ]], views: ['a' => 100, 'b' => 9000, 'c' => 5]);
    expect(app(TrackResolver::class)->resolve('Tems', ['Free Mind'])->youtube_video_id)->toBe('c');
});

test('among equal uploads the most watched wins', function () {
    fakeYouTube(['Free Mind' => [
        video('a', 'Tems - Free Mind', 'Fan A'),
        video('b', 'Tems - Free Mind', 'Fan B'),
    ]], views: ['a' => 100, 'b' => 9000]);
    expect(app(TrackResolver::class)->resolve('Tems', ['Free Mind'])->youtube_video_id)->toBe('b');
});

test('a snippet or a ten-hour loop is not the song', function () {
    fakeYouTube(['Free Mind' => [
        video('short', 'Tems - Free Mind', 'Tems'),
        video('loop', 'Tems - Free Mind', 'Tems - Topic'),
    ]], durations: ['short' => 'PT0M25S', 'loop' => 'PT10H']);

    expect(app(TrackResolver::class)->resolve('Tems', ['Free Mind']))->toBeNull();
});
