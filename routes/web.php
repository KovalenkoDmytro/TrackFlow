<?php

declare(strict_types=1);

use App\Actions\Google\DisconnectGoogleOAuth;
use App\Actions\Google\HandleGoogleOAuthCallback;
use App\Actions\Google\StartGoogleOAuth;
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

// Connects/disconnects the single shared Google OAuth account used by GA4
// Data API reporting (see App\Services\Ga4TokenProvider). Deliberately kept
// OUTSIDE the `verify.shopify` middleware group above — a top-level redirect
// away to accounts.google.com breaks Shopify's embedded iframe session, and
// `can:connect-google` (App\Providers\AppServiceProvider::boot()) is
// sufficient authorization on its own since it is a plain `auth:web` route.
Route::middleware(['auth:web', 'can:connect-google'])
    ->prefix('operator/google')
    ->group(function (): void {
        Route::get('/start', StartGoogleOAuth::class)->name('operator.google.start');
        Route::get('/callback', HandleGoogleOAuthCallback::class)->name('operator.google.callback');
        Route::post('/disconnect', DisconnectGoogleOAuth::class)->name('operator.google.disconnect');
    });

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
