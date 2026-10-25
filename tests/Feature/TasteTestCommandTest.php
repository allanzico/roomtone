<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.qloo.key' => 'test-key',
        'services.qloo.url' => 'https://qloo.test',
        'ai.providers.anthropic.key' => 'ai-key',
        'ai.providers.anthropic.url' => 'https://ai.test/v1',
    ]);
    Cache::flush();

    $this->people = sys_get_temp_dir().'/taste-people-'.uniqid().'.json';
    File::put($this->people, json_encode([['name' => 'Maria', 'loves' => ['Ozark', 'jollof rice', 'Lisbon']]]));
});

afterEach(function () {
    File::delete($this->people);
    File::delete(storage_path('app/taste-test/report.md'));
});

function claudeAnswer(array $input): array
{
    return ['content' => [['type' => 'tool_use', 'name' => 'x', 'input' => $input]]];
}

test('a person goes from three loves to songs with reasons, and invented songs are flagged', function () {
    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, 'qloo.test/search')) {
            return Http::response(['results' => match ($request['query']) {
                'Ozark' => [['entity_id' => 'tv1', 'name' => 'Ozark', 'types' => ['urn:entity:tv_show']]],
                'jollof rice' => [['entity_id' => 'ar9', 'name' => 'Damien Rice', 'types' => ['urn:entity:artist']]],
                'Lisbon' => [['entity_id' => 'loc1', 'name' => 'Lisbon', 'types' => ['urn:entity:locality']]],
            }]);
        }

        if (str_contains($url, 'qloo.test/v2/tags')) {
            return Http::response(['results' => ['tags' => [['id' => 'urn:tag:genre:qloo:'.strtolower($request['filter.query']), 'name' => $request['filter.query']]]]]);
        }

        if (str_contains($url, 'qloo.test/v2/insights')) {
            $artist = isset($request['signal.interests.entities']) ? ['a1', 'Burna Boy', 'Last Last'] : ['a2', 'Mariza', 'Ó Gente da Minha Terra'];

            return Http::response(['results' => ['entities' => [[
                'entity_id' => $artist[0], 'name' => $artist[1], 'popularity' => 0.9,
                'properties' => ['notable_songs' => [$artist[2]]], 'tags' => [],
            ]]]]);
        }

        // Claude: first call resolves the loves, second picks songs.
        return Http::response(match ($request['tool_choice']['name']) {
            'resolve_loves' => claudeAnswer(['loves' => [
                ['love' => 'Ozark', 'pick_id' => 'tv1', 'location' => null, 'genres' => [], 'how' => 'the TV show'],
                ['love' => 'jollof rice', 'pick_id' => null, 'location' => null, 'genres' => ['Afrobeats'], 'how' => 'West African dish, so Afrobeats'],
                ['love' => 'Lisbon', 'pick_id' => 'loc1', 'location' => null, 'genres' => ['Fado'], 'how' => 'the city, so fado'],
            ]]),
            'pick_songs' => claudeAnswer(['picks' => [
                ['love' => 'Ozark', 'artist' => 'Burna Boy', 'song' => 'Last Last'],
                ['love' => 'Lisbon', 'artist' => 'Mariza', 'song' => 'A Song Nobody Wrote'],
            ]]),
        });
    });

    $this->artisan('roomtone:taste', ['file' => $this->people])
        ->expectsOutputToContain('Ozark (tv_show)')
        ->expectsOutputToContain('Fado in Lisbon')
        ->expectsOutputToContain('Last Last, Burna Boy, because Maria loves Ozark')
        ->assertSuccessful();

    $report = File::get(storage_path('app/taste-test/report.md'));

    expect($report)
        ->toContain('- [ ] Last Last, Burna Boy, because Maria loves Ozark')
        ->toContain('A Song Nobody Wrote, Mariza, because Maria loves Lisbon ⚠ not in Qloo list')
        ->toContain('Loves understood: 100% (1 direct Qloo matches, 2 bridged by the agent, 0 missed)');

    // The city went in as a location, not as a signal.
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'insights') && ($r['signal.location.query'] ?? null) === 'Lisbon'
        && ! isset($r['signal.interests.entities']));
});

test('a missing AI key skips the person with a clear message', function () {
    config(['ai.providers.anthropic.key' => null]);
    Http::fake(['qloo.test/*' => Http::response(['results' => []])]);

    $this->artisan('roomtone:taste', ['file' => $this->people])
        ->expectsOutputToContain('AI_PROVIDER_KEY is not set.')
        ->assertSuccessful();
});
