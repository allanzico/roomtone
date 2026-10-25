<?php

namespace App\Services\Qloo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

/**
 * Thin, cached client for the Qloo hackathon API.
 *
 * What we learned the hard way (see CLAUDE.md "Qloo API notes"):
 * - /search is lexical and mixed-type, so it returns candidates for the agent to choose from, not an answer.
 * - Localities and destinations are rejected as insight signals; cities go in as `location` text instead.
 * - Many food and dish tags are silently ignored as artist signals; GuestResolver checks against a baseline.
 * - Mood steering works with the `style:qloo` tags below; `genre:music:{upbeat,danceable,chill}` returns junk.
 *
 * Only the response fields this app uses are cached in the private server-side app cache. Failures are never cached.
 *
 * Qloo answers HTTP 429 at about 4 parallel calls, so every real call (not cache hits) takes one of CONCURRENCY
 * slots, shared by all workers through cache locks, and a 429 is retried with a growing wait.
 */
class QlooClient
{
    /** Entity types that work as an insight signal. */
    public const SIGNAL_TYPES = ['artist', 'tv_show', 'movie', 'book', 'place', 'brand', 'podcast', 'video_game'];

    /** Types that mean "a city or area": use them as `location`, never as a signal. */
    public const LOCATION_TYPES = ['locality', 'destination'];

    /** Plain-language energy steering for the host's requests. */
    public const MOODS = [
        'calm' => 'urn:tag:style:qloo:calm',
        'energetic' => 'urn:tag:style:qloo:energetic',
    ];

    /** Real Qloo calls allowed at once across every worker. Zero 429s at 3 in the spikes; 429s at about 4. */
    public const CONCURRENCY = 3;

    /** Attempts per call when Qloo answers 429; the wait grows by a second each time. */
    private const RATE_LIMIT_ATTEMPTS = 5;

    /** How long a call waits for a free slot before giving up. */
    private const SLOT_WAIT_SECONDS = 120;

    /** A slot frees itself after this long, so a crashed worker cannot hold it forever. Covers 3 tries of 30s. */
    private const SLOT_TTL_SECONDS = 120;

    private const ARTIST = 'urn:entity:artist';

    /**
     * Tag types that hold music genres ("Fado", "Afrobeats", "Hip Hop"). Not urn:tag:genre:music: it mixes in
     * nationalities ("Nigeria", "British", "Korean").
     */
    private const GENRE_TYPES = ['urn:tag:genre:qloo', 'urn:tag:subgenre:qloo'];

    /** Tags filed as genres that only say where an artist is from, or nothing at all. */
    private const NOT_GENRES = ['all', 'american', 'british', 'canadian', 'english', 'australian'];

    /**
     * Possible Qloo entities for something a guest typed. Ordered as Qloo ranks them, minus types we cannot use
     * (albums, people, authors). `kind` is "entity" (pass the id as a signal) or "location" (pass the name as `location`).
     *
     * @return list<array{id: string, name: string, type: string, kind: 'entity'|'location'}>
     */
    public function candidates(string $text, int $max = 8): array
    {
        $text = trim($text);

        if ($text === '') {
            throw new InvalidArgumentException('Search text is empty.');
        }

        /** @var list<array<string, mixed>> $results */
        $results = $this->get('/search', ['query' => $text])['results'] ?? [];

        $candidates = [];

        foreach ($results as $result) {
            $type = $this->usableType($result['types'] ?? []);

            if ($type === null) {
                continue;
            }

            $candidates[] = [
                'id' => (string) $result['entity_id'],
                'name' => (string) $result['name'],
                'type' => $type,
                'kind' => in_array($type, self::LOCATION_TYPES, true) ? 'location' : 'entity',
            ];
        }

        return array_slice($candidates, 0, $max);
    }

    /**
     * Artists Qloo recommends for a set of taste signals. Needs at least one entity, location or tag.
     *
     * @param  list<string>  $entityIds  Signals from candidates() with kind "entity".
     * @param  list<string>  $tags  Steering filters, e.g. MOODS values or ids from tags().
     * @param  list<string>  $signalTags  Tags as a taste signal (a dish or cuisine): only some work, see foodSignal().
     * @return list<array{id: string, name: string, popularity: float, songs: list<string>, tags: list<string>, genres: list<string>}>
     */
    public function artists(array $entityIds = [], ?string $location = null, array $tags = [], int $take = 25, array $signalTags = []): array
    {
        if ($entityIds === [] && ($location === null || trim($location) === '') && $tags === [] && $signalTags === []) {
            throw new InvalidArgumentException('Give at least one entity, location or tag.');
        }

        $params = ['filter.type' => self::ARTIST, 'take' => $take];

        if ($entityIds !== []) {
            $params['signal.interests.entities'] = $this->csv($entityIds);
        }

        if ($signalTags !== []) {
            $params['signal.interests.tags'] = $this->csv($signalTags);
        }

        if ($location !== null && trim($location) !== '') {
            $params['signal.location.query'] = trim($location);
        }

        if ($tags !== []) {
            $params['filter.tags'] = $this->csv($tags);
        }

        /** @var list<array<string, mixed>> $entities */
        $entities = $this->get('/v2/insights', $params)['results']['entities'] ?? [];

        return array_map(fn (array $entity) => [
            'id' => (string) $entity['entity_id'],
            'name' => (string) $entity['name'],
            'popularity' => round((float) ($entity['popularity'] ?? 0), 3),
            'songs' => array_values(array_filter((array) ($entity['properties']['notable_songs'] ?? []), 'is_string')),
            'tags' => array_slice(array_column((array) ($entity['tags'] ?? []), 'name'), 0, 10),
            'genres' => $this->genres((array) ($entity['tags'] ?? [])),
        ], $entities);
    }

    /**
     * Artists for one taste signal, as candidates() labels it: an entity id, or a city name passed as location.
     *
     * @return list<array{id: string, name: string, popularity: float, songs: list<string>, tags: list<string>, genres: list<string>}>
     */
    public function artistsFor(string $kind, string $value, int $take = 15): array
    {
        return match ($kind) {
            'entity' => $this->artists([$value], take: $take),
            'location' => $this->artists(location: $value, take: $take),
            'tag' => $this->artists(take: $take, signalTags: [$value]),
            default => throw new InvalidArgumentException("Unknown signal kind: {$kind}"),
        };
    }

    /**
     * Tag lookup, for genres the host asks for in plain language ("more Afrobeats").
     *
     * @return list<array{id: string, name: string}>
     */
    public function tags(string $query, int $take = 5): array
    {
        /** @var list<array<string, mixed>> $tags */
        $tags = $this->get('/v2/tags', [
            'filter.query' => trim($query),
            'filter.parents.types' => self::ARTIST,
            'take' => $take,
        ])['results']['tags'] ?? [];

        return array_map(fn (array $tag) => ['id' => (string) $tag['id'], 'name' => (string) $tag['name']], $tags);
    }

    /**
     * Food and dish tags for something a guest typed ("burger", "beef steak"): cuisine, specialty dish and menu
     * highlight tags, in Qloo's order.
     *
     * @return list<array{id: string, name: string}>
     */
    public function foodTags(string $query, int $take = 8): array
    {
        /** @var list<array<string, mixed>> $tags */
        $tags = $this->get('/v2/tags', ['filter.query' => trim($query), 'take' => $take])['results']['tags'] ?? [];

        return array_values(array_map(
            fn (array $tag) => ['id' => (string) $tag['id'], 'name' => (string) $tag['name']],
            array_filter($tags, fn (array $tag) => preg_match('/^urn:tag:(cuisine|specialty_dish|menu_highlight):/', (string) $tag['id']) === 1),
        ));
    }

    /**
     * A tag Qloo does not know. As a signal it returns Qloo's default list, which is what a tag that Qloo ignores
     * returns too: a tag is a real signal only when its artists differ from this baseline.
     */
    public const IGNORED_TAG = 'urn:tag:cuisine:qloo:zzzzzz';

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    private function get(string $path, array $params): array
    {
        ksort($params);
        $key = 'qloo:'.sha1($path.'?'.http_build_query($params));

        // Existing installations may have full responses under this key. Trim them on their next read without
        // spending Qloo quota again.
        $cached = Cache::get($key);
        if (is_array($cached)) {
            $needed = $this->neededFields($path, $cached);
            if ($needed !== $cached) {
                Cache::forever($key, $needed);
            }

            return $needed;
        }

        return Cache::rememberForever(
            $key,
            fn () => $this->neededFields($path, $this->request($path, $params)),
        );
    }

    /**
     * Keep only the response data needed to resolve interests and build artist pools. Qloo can return much larger
     * entity records (biographies, links, images, etc.) that Roomtone never uses.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function neededFields(string $path, array $response): array
    {
        if ($path === '/search') {
            return ['results' => array_map(fn (array $item) => array_intersect_key($item, array_flip(['entity_id', 'name', 'types'])), (array) ($response['results'] ?? []))];
        }

        if ($path === '/v2/tags') {
            return ['results' => ['tags' => array_map(fn (array $tag) => array_intersect_key($tag, array_flip(['id', 'name'])), (array) ($response['results']['tags'] ?? []))]];
        }

        if ($path === '/v2/insights') {
            return ['results' => ['entities' => array_map(fn (array $entity) => [
                'entity_id' => $entity['entity_id'],
                'name' => $entity['name'],
                'popularity' => $entity['popularity'] ?? 0,
                'properties' => ['notable_songs' => $entity['properties']['notable_songs'] ?? []],
                'tags' => array_map(fn (array $tag) => array_intersect_key($tag, array_flip(['name', 'type'])), (array) ($entity['tags'] ?? [])),
            ], (array) ($response['results']['entities'] ?? []))]];
        }

        return [];
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    private function request(string $path, array $params): array
    {
        $key = config('services.qloo.key');

        if (! is_string($key) || $key === '') {
            throw new QlooException('QLOO_API_KEY is not set.');
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->inSlot(fn () => $this->http($key)->get($path, $params));
            } catch (ConnectionException $e) {
                throw new QlooException("Qloo {$path} is unreachable: {$e->getMessage()}", 0, $e);
            }

            if ($response->status() === 429 && $attempt < self::RATE_LIMIT_ATTEMPTS) {
                // Should not happen at the concurrency cap; if it does, the log says the cap is too high.
                Log::warning('Qloo rate limited, retrying', ['path' => $path, 'attempt' => $attempt]);
                Sleep::for($attempt)->seconds();

                continue;
            }

            if ($response->failed()) {
                // Rate limits answer {"error_msg": ...}; bad requests answer {"errors": [{"message": ...}]}.
                $detail = $response->json('error_msg') ?? $response->json('errors.0.message') ?? $response->reason();

                throw new QlooException("Qloo {$path} failed ({$response->status()}): {$detail}", $response->status());
            }

            return (array) $response->json();
        }
    }

    /**
     * Run a call while holding one of the shared slots, waiting for a free one if all are taken.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function inSlot(callable $call): mixed
    {
        for ($wait = 0; $wait <= self::SLOT_WAIT_SECONDS * 10; $wait++) {
            for ($slot = 0; $slot < self::CONCURRENCY; $slot++) {
                $lock = Cache::lock("qloo:slot:{$slot}", self::SLOT_TTL_SECONDS);

                if ($lock->get()) {
                    try {
                        return $call();
                    } finally {
                        $lock->release();
                    }
                }
            }

            Sleep::for(100)->milliseconds();
        }

        throw new QlooException('Timed out waiting for a free Qloo slot.');
    }

    private function http(string $key): PendingRequest
    {
        return Http::baseUrl((string) config('services.qloo.url'))
            ->withHeaders(['X-Api-Key' => $key])
            ->acceptJson()
            ->timeout(30)
            // Retry connection problems and 5xx only; a 4xx means we asked wrong, and asking again will not help.
            ->retry(2, 500, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }

    /**
     * Genre tag names in Qloo's order, one per spelling ("R & B" and "R&B" are one genre; "Hip Hop" is preferred
     * over "Hip - Hop"), at most 8.
     *
     * @param  array<mixed>  $tags
     * @return list<string>
     */
    private function genres(array $tags): array
    {
        $genres = [];

        foreach ($tags as $tag) {
            if (! is_array($tag) || ! in_array($tag['type'] ?? null, self::GENRE_TYPES, true) || ! is_string($tag['name'] ?? null)) {
                continue;
            }

            $key = self::genreKey($tag['name']);

            if ($key === '' || in_array($key, self::NOT_GENRES, true)) {
                continue;
            }

            if (! isset($genres[$key]) || (str_contains($genres[$key], ' - ') && ! str_contains($tag['name'], ' - '))) {
                $genres[$key] = trim($tag['name']);
            }
        }

        return array_slice(array_values($genres), 0, 8);
    }

    /** One key per genre whatever the spelling: "R & B" and "R&B" give "rb", "Hip - Hop" and "Hip Hop" give "hiphop". */
    public static function genreKey(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($name));
    }

    /**
     * @param  list<mixed>  $types  Qloo type URNs, e.g. "urn:entity:tv_show".
     */
    private function usableType(array $types): ?string
    {
        $short = array_map(fn ($type) => substr((string) $type, strlen('urn:entity:')), $types);

        foreach ([...self::SIGNAL_TYPES, ...self::LOCATION_TYPES] as $type) {
            if (in_array($type, $short, true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $values
     */
    private function csv(array $values): string
    {
        $values = array_values(array_unique($values));
        sort($values);

        return implode(',', $values);
    }
}
