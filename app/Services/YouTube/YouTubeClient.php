<?php

namespace App\Services\YouTube;

use Illuminate\Http\Client\ConnectionException;
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

        /** @var list<array<string, mixed>> $items */
        $items = $this->get('search', [
            'part' => 'snippet',
            'q' => $query,
            'type' => 'video',
            'videoEmbeddable' => 'true',
            'maxResults' => $max,
        ])['items'] ?? [];

        return array_values(array_map(fn (array $item) => [
            'id' => (string) $item['id']['videoId'],
            'title' => html_entity_decode((string) $item['snippet']['title'], ENT_QUOTES),
            'channel' => html_entity_decode((string) $item['snippet']['channelTitle'], ENT_QUOTES),
        ], array_filter($items, fn (array $item) => isset($item['id']['videoId']))));
    }

    /**
     * The ids, of those given, that are public, processed and embeddable, in the order given.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    public function playable(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $items */
        $items = $this->get('videos', ['part' => 'status', 'id' => implode(',', array_slice($ids, 0, 50))])['items'] ?? [];

        $ok = [];
        foreach ($items as $item) {
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
        $key = config('services.youtube.key');

        if (! is_string($key) || $key === '') {
            throw new YouTubeException('YOUTUBE_API_KEY is not set.');
        }

        try {
            $response = Http::baseUrl((string) config('services.youtube.url'))
                ->acceptJson()
                ->timeout(15)
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
