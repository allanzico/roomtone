<?php

namespace App\Services\YouTube;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * YouTube Data API v3, for finding a playable video for a track.
 *
 * Quota (Google's docs, checked Oct 2026): search.list has its own bucket of 100 calls a day; videos.list costs 1 of
 * 10,000 units. Both reset at midnight Pacific time. When the search bucket runs out, searches stop until then.
 * Results are not cached here: the caller stores only the chosen video id on the track.
 */
class YouTubeClient
{
    private const QUOTA_OUT = 'youtube:quota-out';

    /**
     * Videos for a query, in YouTube's order. Only embeddable videos. No category filter: many artists' uploads are
     * not tagged Music. With no good match YouTube returns unrelated popular videos, so the caller must check them.
     *
     * @return list<array{id: string, title: string, channel: string}>
     */
    public function search(string $query, int $max = 6): array
    {
        if (Cache::has(self::QUOTA_OUT)) {
            throw new YouTubeException('YouTube search is paused: the search quota is used up for now.');
        }

        return $this->videos((array) ($this->get('search', $this->searchParams($query, $max))['items'] ?? []));
    }

    /**
     * Several searches at once (each still counts against the 100 a day). A search that fails is null, so the
     * caller can tell "no video" from "could not look".
     *
     * @param  array<int, string>  $queries
     * @return array<int, list<array{id: string, title: string, channel: string}>|null>
     */
    public function searchMany(array $queries, int $max = 6): array
    {
        if (Cache::has(self::QUOTA_OUT)) {
            throw new YouTubeException('YouTube search is paused: the search quota is used up for now.');
        }

        $key = $this->key();
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (int $i, string $query) => $this->request($pool->as((string) $i))->get('search', [...$this->searchParams($query, $max), 'key' => $key]),
            array_keys($queries),
            $queries,
        ));

        $results = [];
        foreach ($queries as $i => $query) {
            $response = $responses[(string) $i] ?? null;

            if (! $response instanceof Response || $response->failed()) {
                if ($response instanceof Response) {
                    $this->noteQuota('search', $response);
                }

                $results[$i] = null;

                continue;
            }

            $results[$i] = $this->videos((array) ($response->json('items') ?? []));
        }

        return $results;
    }

    /**
     * @return array<string, scalar>
     */
    private function searchParams(string $query, int $max): array
    {
        return ['part' => 'snippet', 'q' => $query, 'type' => 'video', 'videoEmbeddable' => 'true', 'maxResults' => $max];
    }

    /**
     * @param  array<mixed>  $items
     * @return list<array{id: string, title: string, channel: string}>
     */
    private function videos(array $items): array
    {
        $videos = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['id']['videoId'])) {
                $videos[] = [
                    'id' => (string) $item['id']['videoId'],
                    'title' => html_entity_decode((string) $item['snippet']['title'], ENT_QUOTES),
                    'channel' => html_entity_decode((string) $item['snippet']['channelTitle'], ENT_QUOTES),
                ];
            }
        }

        return $videos;
    }

    /**
     * The ids, of those given, that are public, processed and embeddable, in the order given. One call per 50 ids.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    public function playable(array $ids): array
    {
        $items = [];
        foreach (array_chunk(array_values(array_unique($ids)), 50) as $chunk) {
            $items = [...$items, ...(array) ($this->get('videos', ['part' => 'status', 'id' => implode(',', $chunk)])['items'] ?? [])];
        }

        $ok = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $status = (array) ($item['status'] ?? []);

            if (($status['embeddable'] ?? false) === true
                && ($status['privacyStatus'] ?? null) === 'public'
                && ($status['uploadStatus'] ?? null) === 'processed') {
                $ok[] = (string) $item['id'];
            }
        }

        return array_values(array_intersect($ids, $ok));
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    private function get(string $method, array $params): array
    {
        $key = $this->key();

        try {
            $response = $this->request(Http::createPendingRequest())
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->get($method, [...$params, 'key' => $key]);
        } catch (ConnectionException $e) {
            throw new YouTubeException("YouTube {$method} is unreachable: {$e->getMessage()}", 0, $e);
        }

        if ($response->failed()) {
            $this->noteQuota($method, $response);

            $detail = $response->json('error.message') ?? $response->reason();

            throw new YouTubeException("YouTube {$method} failed ({$response->status()}): {$detail}", $response->status());
        }

        return (array) $response->json();
    }

    private function key(): string
    {
        $key = config('services.youtube.key');

        if (! is_string($key) || $key === '') {
            throw new YouTubeException('YOUTUBE_API_KEY is not set.');
        }

        return $key;
    }

    private function request(PendingRequest $request): PendingRequest
    {
        return $request->baseUrl((string) config('services.youtube.url'))->acceptJson()->timeout(15);
    }

    /**
     * Stop searching when Google says the search quota is used up: until midnight Pacific time for the daily
     * bucket (a 403 quotaExceeded, or a 429 whose details name a per-day limit), for a minute on any other 429.
     */
    private function noteQuota(string $method, Response $response): void
    {
        if ($method !== 'search') {
            return;
        }

        $reason = $response->json('error.errors.0.reason');
        $limits = collect((array) $response->json('error.details'))
            ->map(fn ($detail) => is_array($detail) ? (string) ($detail['metadata']['quota_limit'] ?? '') : '');
        $daily = in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true)
            || $limits->contains(fn (string $limit) => str_contains($limit, 'PerDay'));

        if ($daily) {
            Cache::put(self::QUOTA_OUT, true, now('America/Los_Angeles')->addDay()->startOfDay());
        } elseif ($response->status() === 429) {
            Cache::put(self::QUOTA_OUT, true, now()->addMinute());
        }
    }
}
