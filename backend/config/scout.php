<?php

return [
    'driver' => env('SCOUT_DRIVER', 'database'),
    'prefix' => env('SCOUT_PREFIX', ''),
    'queue' => env('SCOUT_QUEUE', false),

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://meilisearch:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [
            'articles' => [
                'filterableAttributes' => [
                    'type',
                    'is_sponsored',
                    'category_slug',
                    'author_slug',
                    'published_at',
                ],
                'sortableAttributes' => [
                    'published_at',
                ],
            ],
        ],
    ],
];
