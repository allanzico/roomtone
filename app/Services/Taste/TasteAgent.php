<?php

namespace App\Services\Taste;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The two judgement calls Qloo cannot make on its own (taste test only, not the Phase 5 agent):
 * 1. resolve(): read a guest's loves, pick the right search candidate or bridge food and places to genres.
 * 2. pick(): choose songs from Qloo's artists and say which love each one is for.
 *
 * Talks to the Claude Messages API directly with a forced tool call, so the answer is always JSON.
 */
class TasteAgent
{
    private const RESOLVE = <<<'TXT'
    You help a party music app turn the three things a guest loves into inputs for the Qloo taste API.
    For each love you get Qloo's search candidates. Search is lexical and mixed-type, so most candidates are lookalikes.

    For every love, return exactly one entry:
    - pick_id: a candidate id ONLY if that candidate is clearly the very thing the guest meant (same thing, sensible type).
      Lookalike names are wrong: "Alfama" is not Alfamart, "jollof rice" is not Damien Rice. If unsure, null.
    - location: if the love is a city, neighbourhood or region, the city name (in English). Otherwise null.
    - genres: when pick_id is null, one or two music genres a fan of this love would enjoy, as plain genre names
      (e.g. "Afrobeats", "Fado", "UK Garage"). A food, drink or cuisine maps to music of the culture it comes from.
      A place maps to music from there. A hobby or vibe maps to music that fits it. Empty when pick_id is set.
    - how: at most eight words on how you read it, e.g. "West African dish, so Afrobeats".
    TXT;

    private const PICK = <<<'TXT'
    You are the DJ of a party music app. A guest told us three things they love; for each love Qloo suggested artists,
    each with their notable songs. Choose up to three songs for this guest, one per love where you can.

    Rules:
    - Only use an artist and a song from the lists you are given. Never invent a song.
    - Prefer songs that work at a party and that a fan of that love would recognise as theirs.
    - love must be the love exactly as the guest typed it.
    TXT;

    /**
     * @param  list<array{love: string, candidates: list<array{id: string, name: string, type: string, kind: string}>}>  $loves
     * @return list<array{love: string, pick_id: ?string, location: ?string, genres: list<string>, how: string}>
     */
    public function resolve(string $guest, array $loves): array
    {
        $result = $this->call(self::RESOLVE, ['guest' => $guest, 'loves' => $loves], 'resolve_loves', [
            'type' => 'object',
            'properties' => [
                'loves' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'love' => ['type' => 'string'],
                            'pick_id' => ['type' => ['string', 'null']],
                            'location' => ['type' => ['string', 'null']],
                            'genres' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 2],
                            'how' => ['type' => 'string'],
                        ],
                        'required' => ['love', 'pick_id', 'location', 'genres', 'how'],
                    ],
                ],
            ],
            'required' => ['loves'],
        ]);

        return array_values(array_map(fn (array $love) => [
            'love' => (string) $love['love'],
            'pick_id' => isset($love['pick_id']) && $love['pick_id'] !== '' ? (string) $love['pick_id'] : null,
            'location' => isset($love['location']) && $love['location'] !== '' ? (string) $love['location'] : null,
            'genres' => array_values(array_map('strval', (array) ($love['genres'] ?? []))),
            'how' => (string) ($love['how'] ?? ''),
        ], (array) ($result['loves'] ?? [])));
    }

    /**
     * @param  list<array{love: string, artists: list<array{name: string, songs: list<string>}>}>  $options
     * @return list<array{love: string, artist: string, song: string}>
     */
    public function pick(string $guest, array $options): array
    {
        $result = $this->call(self::PICK, ['guest' => $guest, 'options' => $options], 'pick_songs', [
            'type' => 'object',
            'properties' => [
                'picks' => [
                    'type' => 'array',
                    'maxItems' => 3,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'love' => ['type' => 'string'],
                            'artist' => ['type' => 'string'],
                            'song' => ['type' => 'string'],
                        ],
                        'required' => ['love', 'artist', 'song'],
                    ],
                ],
            ],
            'required' => ['picks'],
        ]);

        return array_values(array_map(fn (array $pick) => [
            'love' => (string) $pick['love'],
            'artist' => (string) $pick['artist'],
            'song' => (string) $pick['song'],
        ], (array) ($result['picks'] ?? [])));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function call(string $system, array $input, string $tool, array $schema): array
    {
        // The DJ agent's own provider settings (config/ai.php), so one AI_PROVIDER_URL serves both.
        $key = config('ai.providers.anthropic.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('AI_PROVIDER_KEY is not set.');
        }

        try {
            $response = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                ->acceptJson()
                ->timeout(60)
                ->retry(2, 1000, throw: false)
                ->post(rtrim((string) config('ai.providers.anthropic.url'), '/').'/messages', [
                    'model' => config('ai.providers.anthropic.models.text.default'),
                    'max_tokens' => 2000,
                    'system' => $system,
                    'tools' => [['name' => $tool, 'description' => 'Return the answer.', 'input_schema' => $schema]],
                    'tool_choice' => ['type' => 'tool', 'name' => $tool],
                    'messages' => [['role' => 'user', 'content' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]],
                ])
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException("Claude call failed ({$e->response->status()}): ".($e->response->json('error.message') ?? $e->response->reason()), 0, $e);
        }

        foreach ((array) $response->json('content') as $block) {
            if (($block['type'] ?? null) === 'tool_use') {
                return (array) $block['input'];
            }
        }

        throw new RuntimeException('Claude did not return a tool call.');
    }
}
