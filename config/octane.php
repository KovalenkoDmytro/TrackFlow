<?php

declare(strict_types=1);

return [
    'server' => env('OCTANE_SERVER', 'frankenphp'),

    'host' => env('OCTANE_HOST', '127.0.0.1'),

    'port' => env('OCTANE_PORT', 8000),

    'workers' => env('OCTANE_WORKERS', 'auto'),

    'max_requests' => env('OCTANE_MAX_REQUESTS', 500),

    'memory_limit' => env('OCTANE_MEMORY_LIMIT', '512M'),

    'timeout' => env('OCTANE_TIMEOUT', 30),

    'tick_frequency' => env('OCTANE_TICK_FREQUENCY', 0),

    'extension' => env('OCTANE_EXTENSION'),

    'admin' => [
        'host' => env('OCTANE_ADMIN_HOST', '127.0.0.1'),
        'port' => env('OCTANE_ADMIN_PORT', 9001),
    ],

    'listeners' => [
        \Laravel\Octane\Events\RequestReceived::class => [
            \App\Octane\Listeners\FlushShopifyBindings::class,
        ],
    ],

    'warm' => [],

    'garbage_collection_interval' => null,
];
