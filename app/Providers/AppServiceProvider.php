<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Shopify\AuthenticateShopify;
use App\Contracts\PlatformResolverContract;
use App\Services\PlatformResolver;
use App\Services\Shopify\LoggingApiHelper;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application services into the IoC container.
     *
     * PlatformResolverContract is bound to PlatformResolver so every caller
     * (Actions, Controllers) depends on the interface, not the concrete class.
     * This makes it trivial to swap the resolver in tests or feature flags.
     */
    public function register(): void
    {
        $this->app->bind(
            PlatformResolverContract::class,
            PlatformResolver::class,
        );

        $this->excludeAuthenticateFromVendorRoutes();
    }

    /**
     * Bootstrap application services after all providers have been registered.
     */
    public function boot(): void
    {
        if (config('app.env') !== 'local') {
            URL::forceScheme('https');
        }

        // Rebind (not register()) so this overrides the package's own
        // `$this->app->bind(IApiHelper::class, ...)` call in
        // ShopifyAppProvider::register(), regardless of provider load order —
        // boot() always runs after every provider's register() phase.
        // See App\Services\Shopify\LoggingApiHelper for why.
        $this->app->bind(IApiHelper::class, LoggingApiHelper::class);

        $this->guardAgainstUnsupportedShopifyRouteConfig();
    }

    /**
     * Forces "authenticate" into `shopify-app.manual_routes` at the config
     * level, regardless of what SHOPIFY_MANUAL_ROUTES happens to be set to in
     * the environment (an operator's .env can easily omit it — ours currently
     * does not set it at all).
     *
     * Without this, `ShopifyAppProvider::boot()` (which runs before
     * routes/web.php is loaded — package providers boot before app providers,
     * and route files are loaded only after every provider has booted)
     * decides whether to register its own `AuthController@authenticate` route
     * purely from that raw env value. If "authenticate" is missing from it,
     * the vendor route DOES get registered, and our own `/authenticate`
     * registration in routes/web.php (loaded later still) only "wins" by
     * silently overwriting it in the `RouteCollection`, because both routes
     * share the same `methods|domain|uri` key. That accidental load-order
     * safety net is fragile and would silently break if route-loading order
     * ever changed.
     *
     * Doing it here in register() — which runs for every provider before any
     * provider's boot() — guarantees the vendor's own
     * `Util::registerPackageRoute('authenticate', ...)` check (see
     * vendor/kyon147/laravel-shopify/src/resources/routes/shopify.php)
     * correctly skips the route on its own terms. We no longer depend on
     * load order at all.
     *
     * @see AuthenticateShopify
     */
    private function excludeAuthenticateFromVendorRoutes(): void
    {
        $manualRoutes = collect(explode(',', (string) config('shopify-app.manual_routes')))
            ->map(static fn (string $route): string => trim($route))
            ->filter()
            ->push('authenticate')
            ->unique()
            ->implode(',');

        config(['shopify-app.manual_routes' => $manualRoutes]);
    }

    /**
     * routes/web.php registers `/authenticate` as a plain top-level route (see
     * App\Actions\Shopify\AuthenticateShopify) — it does NOT wrap it in the
     * `domain`/`prefix` route group that the vendor package's own route file
     * applies to all of its routes (see
     * vendor/kyon147/laravel-shopify/src/resources/routes/shopify.php).
     *
     * That is harmless today because `SHOPIFY_DOMAIN`/`SHOPIFY_APP_PREFIX` are
     * both empty by default, but if either is ever configured, our manual
     * route would silently stop matching what Shopify actually calls back to
     * (wrong domain/prefix), reintroducing the very 500 this Action exists to
     * prevent. Fail fast at boot time instead of leaving that as a landmine.
     */
    private function guardAgainstUnsupportedShopifyRouteConfig(): void
    {
        if (config('shopify-app.domain') || config('shopify-app.prefix')) {
            throw new RuntimeException(
                'SHOPIFY_DOMAIN/SHOPIFY_APP_PREFIX is set, but routes/web.php registers '
                .'/authenticate without accounting for domain/prefix (see '
                .'App\Actions\Shopify\AuthenticateShopify). Update the manual route '
                .'registration to respect these settings before enabling them.',
            );
        }
    }
}
