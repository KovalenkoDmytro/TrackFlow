<?php

declare(strict_types=1);

use App\Http\Middleware\DevShopAuth;
use Illuminate\Support\Facades\Route;

Route::middleware(['verify.shopify'])->group(function (): void {
    // All app routes are handled client-side by React Router.
    // Laravel serves the SPA entry point for every path so that direct URL
    // navigation and Shopify iframe deep-links resolve correctly.
    Route::get('/', fn () => view('spa'))->name('home');
    Route::get('/settings/{any}', fn () => view('spa'))->where('any', '.*');
});

if (app()->isLocal()) {
    Route::middleware([DevShopAuth::class])->prefix('dev')->group(function (): void {
        Route::get('/{any}', fn () => view('spa'))->name('dev.home')->where('any', '.*');
    });
}
