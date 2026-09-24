<?php

declare(strict_types=1);

use App\Octane\Listeners\FlushShopifyBindings;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CloseMonologHandlers;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Octane;

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

    /*
    |--------------------------------------------------------------------------
    | Octane Listeners
    |--------------------------------------------------------------------------
    |
    | Octane's own service provider replaces (not merges with) this array
    | wholesale for every event key, so it must restate the full stock listener
    | set from vendor/laravel/octane/config/octane.php for every event —
    | omitting one (as a previous version of this file did for
    | RequestReceived) silently drops listeners like FlushAuthenticationState
    | and FlushSessionState, letting one shop's authenticated user leak into
    | the next request handled by the same Octane worker.
    |
    */
    'listeners' => [
        WorkerStarting::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureUploadedFilesCanBeMoved::class,
        ],

        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
            FlushShopifyBindings::class,
        ],

        RequestHandled::class => [
            //
        ],

        RequestTerminated::class => [
            // FlushUploadedFiles::class,
        ],

        TaskReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TaskTerminated::class => [
            //
        ],

        TickReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TickTerminated::class => [
            //
        ],

        OperationTerminated::class => [
            FlushOnce::class,
            FlushTemporaryContainerInstances::class,
            // DisconnectFromDatabases::class,
            // CollectGarbage::class,
        ],

        WorkerErrorOccurred::class => [
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            CloseMonologHandlers::class,
        ],
    ],

    'warm' => [],

    'garbage_collection_interval' => null,
];
