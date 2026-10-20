<?php

namespace App\Services\Qloo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Thin, cached client for the Qloo hackathon API.
 *
 * What we learned the hard way (see CLAUDE.md "Qloo API notes"):
 * - /search is lexical and mixed-type, so it returns candidates for the agent to choose from, not an answer.
 * - Localities and destinations are rejected as insight signals; cities go in as `location` text instead.
 * - Food and dish tags are silently ignored as artist signals; a restaurant entity works as a loose bridge.
 * - Mood steering works with the `style:qloo` tags below; `genre:music:{upbeat,danceable,chill}` returns junk.
 *
 * Responses are cached forever in the app cache (the Qloo terms allow private server-side caching). Failures are
 * never cached.
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

    private const ARTIST = 'urn:entity:artist';

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
     * @return list<array{id: string, name: string, popularity: float, songs: list<string>, tags: list<string>}>
     */
    public function artists(array $entityIds = [], ?string $location = null, array $tags = [], int $take = 25): array
    {
        if ($entityIds === [] && ($location === null || trim($location) === '') && $tags === []) {
            throw new InvalidArgumentException('Give at least one entity, location or tag.');
        }

        $params = ['filter.type' => self::ARTIST, 'take' => $take];

        if ($entityIds !== []) {
            $params['signal.interests.entities'] = $this->csv($entityIds);
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
        ], $entities);
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
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    private function get(string $path, array $params): array
    {
        ksort($params);

        return Cache::rememberForever(
            'qloo:'.sha1($path.'?'.http_build_query($params)),
            fn () => $this->request($path, $params),
        );
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

        try {
            $response = $this->http($key)->get($path, $params)->throw();
        } catch (RequestException $e) {
            $detail = $e->response->json('errors.0.message') ?? $e->response->reason();

            throw new QlooException("Qloo {$path} failed ({$e->response->status()}): {$detail}", $e->response->status(), $e);
        } catch (ConnectionException $e) {
            throw new QlooException("Qloo {$path} is unreachable: {$e->getMessage()}", 0, $e);
        }

        return (array) $response->json();
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
