<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\Ga4ApiController;
use App\Http\Controllers\Api\GoogleAdsApiController;
use App\Http\Controllers\Api\MetaApiController;
use App\Http\Controllers\Api\PixelApiController;
use App\Http\Controllers\Api\ShopStatusController;
use App\Http\Controllers\ConversionController;
use Illuminate\Support\Facades\Route;

Route::post('/conversions', [ConversionController::class, 'track']);

Route::middleware(['auth:web'])->group(function (): void {
    Route::get('/shop-status', [ShopStatusController::class, 'index']);
    Route::get('/analytics', [AnalyticsController::class, 'index']);

    Route::put('/pixel', [PixelApiController::class, 'update']);

    Route::prefix('settings')->group(function (): void {
        Route::get('/google-ads', [GoogleAdsApiController::class, 'show']);
        Route::post('/google-ads', [GoogleAdsApiController::class, 'store']);
        Route::delete('/google-ads', [GoogleAdsApiController::class, 'destroy']);

        Route::get('/ga4', [Ga4ApiController::class, 'show']);
        Route::post('/ga4', [Ga4ApiController::class, 'store']);
        Route::delete('/ga4', [Ga4ApiController::class, 'destroy']);

        Route::get('/meta', [MetaApiController::class, 'show']);
        Route::post('/meta', [MetaApiController::class, 'store']);
        Route::delete('/meta', [MetaApiController::class, 'destroy']);
    });
});
