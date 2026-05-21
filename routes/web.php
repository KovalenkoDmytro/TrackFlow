<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\Settings\Ga4Controller;
use App\Http\Controllers\Settings\GoogleAdsController;
use App\Http\Controllers\Settings\MetaController;
use App\Http\Controllers\Settings\TikTokController;
use Illuminate\Support\Facades\Route;

Route::middleware(['verify.shopify'])->group(function (): void {
    Route::get('/', [HomeController::class, 'index'])->name('home');

    Route::prefix('settings')->name('settings.')->group(function (): void {
        Route::get('/google-ads',    [GoogleAdsController::class, 'show'])->name('google-ads');
        Route::post('/google-ads',   [GoogleAdsController::class, 'store'])->name('google-ads.store');
        Route::delete('/google-ads', [GoogleAdsController::class, 'destroy'])->name('google-ads.destroy');

        Route::get('/meta',   [MetaController::class,   'show'])->name('meta');
        Route::get('/tiktok', [TikTokController::class, 'show'])->name('tiktok');
        Route::get('/ga4',    [Ga4Controller::class,    'show'])->name('ga4');
    });
});

if (app()->isLocal()) {
    // Authenticate as the dev shop for all /dev/* routes
    Route::middleware([App\Http\Middleware\DevShopAuth::class])->prefix('dev')->group(function (): void {

        Route::get('/preview', [HomeController::class, 'index']);

        Route::get('/home', [HomeController::class, 'index'])->name('dev.home');

        // Google Ads settings — full CRUD without Shopify session
        Route::get('/settings/google-ads',    [GoogleAdsController::class, 'show'])->name('dev.settings.google-ads');
        Route::post('/settings/google-ads',   [GoogleAdsController::class, 'store']);
        Route::delete('/settings/google-ads', [GoogleAdsController::class, 'destroy']);
    });
}
