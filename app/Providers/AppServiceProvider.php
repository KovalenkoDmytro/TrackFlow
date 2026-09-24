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

        $this->excludeVendorRoutesFromRegistration();
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
        $this->guardAgainstUnsupportedShopifyDevBypass();
        $this->guardAgainstBlankShopifyCredentials();
    }

    /**
     * Forces "authenticate" and "api" into `shopify-app.manual_routes` at the
     * config level, regardless of what SHOPIFY_MANUAL_ROUTES happens to be
     * set to in the environment (an operator's .env can easily omit either —
     * ours currently does not set this at all).
     *
     * "authenticate": without this, `ShopifyAppProvider::boot()` (which runs
     * before routes/web.php is loaded — package providers boot before app
     * providers, and route files are loaded only after every provider has
     * booted) decides whether to register its own `AuthController@authenticate`
     * route purely from that raw env value. If "authenticate" is missing from
     * it, the vendor route DOES get registered, and our own `/authenticate`
     * registration in routes/web.php (loaded later still) only "wins" by
     * silently overwriting it in the `RouteCollection`, because both routes
     * share the same `methods|domain|uri` key. That accidental load-order
     * safety net is fragile and would silently break if route-loading order
     * ever changed.
     *
     * "api": the vendor package also registers `GET /api`, `/api/me`, and
     * `/api/plans` (see vendor/kyon147/laravel-shopify/src/resources/routes/api.php)
     * behind its own `verify.shopify` middleware — the exact bypass-prone
     * middleware this app's App\Http\Middleware\AuthenticateShopifySessionToken
     * replaces on every route we define ourselves in routes/api.php. Left
     * enabled, those three vendor routes would keep the original bypass alive
     * on a side door we never touch. This app has no use for them (no
     * plan/billing UI), so they are excluded outright rather than
     * re-protected.
     *
     * "authenticate.token": vendor view `token.blade.php` renders its
     * `target` query param unescaped into a JS template literal — a reflected
     * XSS. Now that App Bridge loads on every shell page, a malicious link
     * opened in the admin iframe could run JS there and call
     * `shopify.idToken()` to exfiltrate a live bearer token. This app's SPA
     * frontend fetches its own session token via App Bridge directly and
     * never needs this MPA token-bridge route (see the `SHOPIFY_FRONTEND_TYPE`
     * comment in .env.example), so it is excluded outright rather than
     * patched inside vendor code.
     *
     * "billing"/"billing.process"/"billing.usage_charge": `VerifyShopify`
     * passes these through with no auth check at all when no HMAC is
     * present, so they would let anyone trigger billing actions against any
     * installed shop's stored offline token. This app has no billing/plan
     * UI, so they are excluded outright rather than re-protected.
     *
     * Doing this here in register() — which runs for every provider before
     * any provider's boot() — guarantees the vendor's own
     * `Util::registerPackageRoute(...)` checks (see
     * vendor/kyon147/laravel-shopify/src/resources/routes/{shopify,api}.php)
     * correctly skip these routes on their own terms. We no longer depend on
     * load order at all.
     *
     * @see AuthenticateShopify
     */
    private function excludeVendorRoutesFromRegistration(): void
    {
        $manualRoutes = collect(explode(',', (string) config('shopify-app.manual_routes')))
            ->map(static fn (string $route): string => trim($route))
            ->filter()
            ->push('authenticate')
            ->push('authenticate.token')
            ->push('api')
            ->push('billing')
            ->push('billing.process')
            ->push('billing.usage_charge')
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

    /**
     * `App\Http\Middleware\AuthenticateShopifySessionToken` bypasses bearer-token
     * verification entirely when `shopify-app.dev_auth_bypass` is true — a
     * deliberate local-only escape hatch for exercising `/api/*` without a real
     * Shopify session token. That middleware only re-checks
     * `app()->environment('local')` at request time, so a misconfigured
     * staging/production `.env` that leaves the flag on would silently reopen
     * the auth bypass this migration closes. Fail fast at boot instead of
     * relying on that request-time check alone.
     */
    private function guardAgainstUnsupportedShopifyDevBypass(): void
    {
        if (config('shopify-app.dev_auth_bypass') === true && ! app()->environment('local')) {
            throw new RuntimeException(
                'SHOPIFY_DEV_AUTH_BYPASS is enabled outside the local environment. '
                .'This bypasses Shopify session-token verification on /api/* and '
                .'must never be enabled in staging or production.',
            );
        }
    }

    /**
     * `App\Actions\Shopify\ResolveShopFromSessionToken` verifies every
     * session-token signature against `config('shopify-app.api_secret')` and
     * every `aud` claim against `config('shopify-app.api_key')`. A blank
     * value for either (e.g. an omitted `.env` entry) makes that verification
     * meaningless: an attacker-signed token with an empty-string HMAC key, or
     * an empty `aud` claim, would pass. Fail fast at boot instead of
     * discovering this in production. Skipped in the testing environment,
     * which sets its own non-empty values in phpunit.xml.
     */
    private function guardAgainstBlankShopifyCredentials(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if (blank(config('shopify-app.api_key')) || blank(config('shopify-app.api_secret'))) {
            throw new RuntimeException(
                'SHOPIFY_API_KEY and SHOPIFY_API_SECRET must both be set to non-empty '
                .'values. A blank secret makes session-token signature verification '
                .'meaningless and would grant access to every shop.',
            );
        }
    }
}
