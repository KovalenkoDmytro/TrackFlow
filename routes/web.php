<?php

declare(strict_types=1);

use App\Http\Middleware\DevShopAuth;
use Illuminate\Support\Facades\Route;


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

Route::any('/webhook/customers-data-request', static function() { return response('OK', 200); });
Route::any('/webhook/customers-redact', static function() { return response('OK', 200); });
Route::any('/webhook/shop-redact', static function() { return response('OK', 200); });
