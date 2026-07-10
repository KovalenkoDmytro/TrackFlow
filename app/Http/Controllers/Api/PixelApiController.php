<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Pixel\SyncWebPixel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pixel\TogglePixelRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * JSON API surface for the merchant-controlled tracking pixel toggle in the React SPA.
 *
 * A Web Pixel has no "enabled/disabled" state on Shopify's side — its existence is
 * what makes it active. Enabling therefore creates the pixel, disabling deletes it,
 * both performed synchronously so the merchant gets immediate, accurate feedback.
 *
 * The underlying gnikyt/basic-shopify-api client applies a 10s default Guzzle
 * request timeout (see Options::$guzzleOptions), bounding how long this
 * synchronous call can block the HTTP request even if Shopify's API stalls.
 */
final class PixelApiController extends Controller
{
    /**
     * Toggle the tracking pixel on or off for the authenticated shop.
     */
    public function update(TogglePixelRequest $request): JsonResponse
    {
        $shop = $request->user();
        $enabled = (bool) $request->validated('enabled');

        try {
            SyncWebPixel::run($shop, $enabled);
        } catch (RuntimeException $e) {
            // Expected failure: Shopify returned userErrors or an invalid response shape.
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            // Unexpected failure: network error, timeout, GuzzleException, etc.
            // Never let this bubble up as an uncaught 500 HTML error page for the SPA.
            Log::error('PixelApiController: unexpected error toggling pixel', [
                'shop' => $shop->name,
                'enabled' => $enabled,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An unexpected error occurred while updating the pixel status.',
            ], 500);
        }

        return response()->json([
            'pixel_enabled' => $shop->refresh()->pixel_enabled,
            'shopify_pixel_id' => $shop->shopify_pixel_id,
        ]);
    }
}
