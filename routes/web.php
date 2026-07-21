<?php

declare(strict_types=1);

use App\Actions\Google\HandleShopGoogleOAuthCallback;
use App\Actions\Google\StartShopGoogleOAuth;
use App\Actions\Shopify\AuthenticateShopify;
use App\Actions\Webhooks\CustomersDataRequestWebhook;
use App\Actions\Webhooks\CustomersRedactWebhook;
use App\Actions\Webhooks\ShopRedactWebhook;
use App\Http\Middleware\DevShopAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Osiset\ShopifyApp\Http\Controllers\WebhookController;

// App\Providers\AppServiceProvider::excludeAuthenticateFromVendorRoutes()
// force-adds "authenticate" to shopify-app.manual_routes during the
// register() phase (before ShopifyAppProvider::boot() decides which of its
// own routes to register), so the kyon147/laravel-shopify package never
// registers its own AuthController@authenticate route here — regardless of
// what SHOPIFY_MANUAL_ROUTES is set to in .env. See
// App\Actions\Shopify\AuthenticateShopify for why this Action exists:
// Shopify's managed installation flow never sends a `code`, so the package's
// legacy OAuth-authorize fallback 500s on every fresh install. This Action
// bridges Shopify's token-exchange flow instead, then delegates back to the
// package's own install/auth logic once an `id_token` is present.
Route::match(['GET', 'POST'], '/authenticate', AuthenticateShopify::class)
    ->name('authenticate');

// Connects the AUTHENTICATED SHOP's own Google account for GA4 Admin API Key
// Event creation (see App\Services\GoogleAnalytics4Client). Every shop
// connects its own account through the app's single OAuth client — the
// resulting refresh_token is stored on that shop's own PlatformIntegration
// row, never shared. Deliberately kept OUTSIDE the `verify.shopify` group
// below — a top-level redirect away to accounts.google.com breaks Shopify's
// embedded iframe session, so this only needs a plain `auth:web` session.
// MUST be registered before the `/settings/{any}` catch-all below (Laravel
// matches routes in registration order), otherwise the SPA catch-all would
// swallow these requests first.
Route::middleware(['auth:web'])
    ->prefix('settings/ga4/google')
    ->group(function (): void {
        Route::get('/start', StartShopGoogleOAuth::class)->name('settings.ga4.google.start');
        Route::get('/callback', HandleShopGoogleOAuthCallback::class)->name('settings.ga4.google.callback');
    });

if (app()->isLocal()) {
    // In local development bypass Shopify OAuth entirely so the React app can
    // be worked on with plain `php artisan serve` + `npm run dev`.
    // DevShopAuth logs in as the dev shop user when a matching User exists;
    // it is a silent no-op otherwise, so fresh checkouts never crash.
    Route::middleware([DevShopAuth::class])->group(function (): void {
        Route::get('/', fn () => view('spa'))->name('dev.home');
        Route::get('/settings/{any}', fn () => view('spa'))->where('any', '.*');
        Route::get('/analytics', fn () => view('spa'));
    });
} else {
    // Production: every route is protected by Shopify's embedded-app auth.
    Route::middleware(['verify.shopify'])->group(function (): void {
        // All app routes are handled client-side by React Router.
        // Laravel serves the SPA entry point for every path so that direct URL
        // navigation and Shopify iframe deep-links resolve correctly.
        Route::get('/', fn () => view('spa'))->name('home');
        Route::get('/settings/{any}', fn () => view('spa'))->where('any', '.*');
        Route::get('/analytics', fn () => view('spa'));
    });
}

Route::post('/webhook/customers-data-request', CustomersDataRequestWebhook::class)->middleware('auth.webhook');
Route::post('/webhook/customers-redact', CustomersRedactWebhook::class)->middleware('auth.webhook');
Route::post('/webhook/shop-redact', ShopRedactWebhook::class)->middleware('auth.webhook');

// SHOPIFY_MANUAL_ROUTES includes "webhook" so the kyon147/laravel-shopify
// package no longer registers its catch-all POST /webhook/{type} route
// (which would otherwise intercept the three explicit routes above and try
// to dispatch nonexistent App\Jobs\{Type}Job classes). The package still
// subscribes to the APP_UNINSTALLED topic at /webhook/app-uninstalled (see
// config/shopify-app.php `webhooks`) and expects it to reach the same
// WebhookController, which resolves App\Jobs\AppUninstalledJob for that
// type — so it must be registered explicitly here to keep working.
Route::post('/webhook/app-uninstalled', function (Request $request) {
    return (new WebhookController)->handle('app-uninstalled', $request);
})->middleware('auth.webhook');
