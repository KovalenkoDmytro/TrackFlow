<?php

declare(strict_types=1);

namespace App\Octane\Listeners;

use Laravel\Octane\Events\RequestReceived;
use Osiset\ShopifyApp\Contracts\Commands\Charge as ChargeCommand;
use Osiset\ShopifyApp\Contracts\Commands\Shop as ShopCommand;
use Osiset\ShopifyApp\Contracts\Queries\Charge as ChargeQuery;
use Osiset\ShopifyApp\Contracts\Queries\Plan as PlanQuery;
use Osiset\ShopifyApp\Contracts\Queries\Shop as ShopQuery;
use Osiset\ShopifyApp\Services\OfflineAccessTokenRefresher;

/**
 * Clears kyon147/laravel-shopify singletons from the request sandbox so each
 * request resolves fresh instances.
 *
 * Without this, shop-specific state stored inside those singletons leaks from
 * request N into request N+1 when Octane reuses the same worker process.
 */
final class FlushShopifyBindings
{
    /**
     * Singletons registered by OsisetShopifyAppServiceProvider that hold
     * mutable, request-specific state (shop session, API helper, etc.).
     *
     * @var list<class-string>
     */
    private const SINGLETONS = [
        ShopQuery::class,
        PlanQuery::class,
        ChargeQuery::class,
        ChargeCommand::class,
        ShopCommand::class,
        OfflineAccessTokenRefresher::class,
    ];

    public function __invoke(RequestReceived $event): void
    {
        foreach (self::SINGLETONS as $abstract) {
            $event->sandbox->forgetInstance($abstract);
        }
    }
}
