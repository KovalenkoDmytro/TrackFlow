<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns a summary of the current shop's status and active platform integrations.
 *
 * Used by the React SPA dashboard to decide which platform cards to render as
 * connected without issuing per-platform requests.
 */
final class ShopStatusController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $request->user();

        $integrations = $shop->platformIntegrations()
            ->where('active', true)
            ->get()
            ->keyBy(fn ($integration) => $integration->platform->value)
            ->map(fn () => true)
            ->toArray();

        return response()->json([
            'shop' => [
                'name' => $shop->name,
                'shopify_pixel_id' => $shop->shopify_pixel_id,
                'pixel_enabled' => (bool) $shop->pixel_enabled,
            ],
            'integrations' => $integrations,
        ]);
    }
}
