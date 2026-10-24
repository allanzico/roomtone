<?php

use App\Enums\SetItemStatus;
use App\Models\Event;
use App\Models\GuestLove;
use App\Services\Dj\DemoParty;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoPartySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DemoPartySeeder::class);
    $this->template = Event::where('code', 'DEMO')->sole();
    // The template's guests have readable loves with stored pools, like a built demo.
    $this->template->guests()->where('name', 'Maria')->sole()->update(['blend' => ['loves' => [$this->template->guests()->where('name', 'Maria')->sole()->loves()->orderBy('id')->first()->id], 'text' => 'Ozark', 'pool' => []]]);
    GuestLove::query()->update(['status' => 'resolved', 'pool' => [['id' => 'q1', 'name' => 'Tems', 'songs' => ['Free Mind'], 'rank' => 1]]]);
});

test('a visitor gets their own copy of the demo, made without any outside call', function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('today 21:00'));

    $copy = app(DemoParty::class)->copy();

    expect($copy->code)->not->toBe('DEMO')
        ->and($copy->demo)->toBeTrue()
        ->and($copy->host_token)->not->toBe($this->template->host_token)
        ->and($copy->energy_plan[0])->toBe(['at' => '21:00', 'energy' => 4, 'label' => 'After dinner'])
        ->and($copy->guests()->count())->toBe($this->template->guests()->count())
        ->and($copy->tasteClusters()->pluck('label')->all())->toBe($this->template->tasteClusters()->pluck('label')->all())
        ->and(GuestLove::whereIn('guest_id', $copy->guests()->select('id'))->where('status', 'resolved')->count())
        ->toBe(GuestLove::whereIn('guest_id', $this->template->guests()->select('id'))->count());

    // Islands and reasons point at the copy's own guests and loves.
    $maria = $copy->guests()->where('name', 'Maria')->sole();
    expect($maria->blend)->toBe(['loves' => [$maria->loves()->orderBy('id')->first()->id], 'text' => 'Ozark', 'pool' => []]);
    expect($maria->clusters()->pluck('event_id')->unique()->all())->toBe([$copy->id]);

    $items = $copy->setItems()->orderBy('position')->get();
    expect($items)->toHaveCount($this->template->setItems()->count())
        ->and($items->pluck('status')->unique()->values()->all())->toBe([SetItemStatus::Playing, SetItemStatus::Queued])
        ->and($items->first()->reason_guest_id)->toBe($maria->id)
        ->and($copy->guests()->pluck('id')->all())->toContain(...$items->pluck('reason_guest_id')->filter()->unique()->all());

    // The template is untouched.
    expect(Event::where('code', 'DEMO')->sole()->setItems()->count())->toBe($items->count());
});

test('"Try a demo party" opens the copy\'s host link', function () {
    $response = $this->post('/demo');

    $copy = Event::where('demo', true)->sole();
    $response->assertRedirect("/e/{$copy->code}/host?key={$copy->host_token}");
});

test('without a demo template the visitor is told to come back shortly', function () {
    $this->template->delete();

    $this->from('/')->post('/demo')->assertRedirect('/')->assertSessionHasErrors('demo');
    expect(Event::count())->toBe(0);
});

test('demo copies are pruned after a day; the template and real parties stay', function () {
    $old = app(DemoParty::class)->copy();
    $this->travel(2)->days();
    $fresh = app(DemoParty::class)->copy();
    $real = Event::create(['code' => 'REAL01', 'name' => 'x', 'host_token' => 'real-token']);
    Event::query()->whereKey($real->id)->update(['created_at' => now()->subDays(3)]);

    $this->artisan('model:prune', ['--model' => Event::class])->assertSuccessful();

    expect(Event::whereKey($old->id)->exists())->toBeFalse()
        ->and(Event::whereKey($fresh->id)->exists())->toBeTrue()
        ->and(Event::where('code', 'DEMO')->exists())->toBeTrue()
        ->and(Event::whereKey($real->id)->exists())->toBeTrue();
});

test('the demo build keeps the existing demo when a key is missing', function () {
    config(['services.qloo.key' => null]);

    $this->artisan('roomtone:demo:build')->expectsOutputToContain('keeping the existing demo')->assertSuccessful();
    expect(Event::where('code', 'DEMO')->sole()->is($this->template))->toBeTrue();
});

test('the demo build keeps the existing demo when the build fails, and leaves nothing behind', function () {
    config(['services.qloo.key' => 'q', 'ai.providers.anthropic.key' => 'a', 'services.youtube.key' => 'y', 'concurrency.default' => 'sync']);
    Http::fake(['*' => Http::response('down', 503)]);
    $before = Event::count();

    $this->artisan('roomtone:demo:build')->expectsOutputToContain('keeping the existing demo')->assertFailed();

    expect(Event::count())->toBe($before)->and(Event::where('code', 'DEMO')->sole()->is($this->template))->toBeTrue();
});

test('a demo that falls below the bar is not used', function () {
    $empty = Event::create(['code' => 'TMP001', 'name' => 'x', 'host_token' => 'tmp-token']);
    $empty->guests()->create(['name' => 'Gran', 'color' => '#ffffff'])->loves()->create(['raw_text' => 'qxzv', 'status' => 'unresolved']);

    expect(DemoParty::readiness($empty))->toMatchArray(['ok' => false, 'guests' => 1, 'guests_with_pools' => 0, 'tracks' => 0]);
});
