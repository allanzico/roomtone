<?php

/*
 * Laravel AI SDK. Roomtone uses one provider and one model: Claude Haiku (AI_MODEL) for the DJ agent. Every
 * model slot points at it so nothing can quietly pick a stronger, pricier model.
 */

return [

    'default' => 'anthropic',

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
            'individually' => true,
        ],
    ],

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('AI_PROVIDER_KEY'),
            'url' => env('AI_PROVIDER_URL', 'https://api.anthropic.com/v1'),
            'models' => [
                'text' => [
                    'default' => env('AI_MODEL', 'claude-haiku-5-5'),
                    'cheapest' => env('AI_MODEL', 'claude-haiku-5-5'),
                    'smartest' => env('AI_MODEL', 'claude-haiku-5-5'),
                ],
            ],
        ],
    ],

];
