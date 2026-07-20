<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Ga4\VerifyGa4PropertyAccess;
use App\Exceptions\GoogleOAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ga4\UpdateShopGa4PropertyRequest;
use App\Models\ShopGa4Setting;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * JSON API surface for assigning a shop's GA4 property_id for the read-only
 * Data API reporting feature (see App\Models\ShopGa4Setting).
 *
 * This is separate from Ga4ApiController, which manages the per-shop
 * Measurement Protocol credentials (measurement_id + api_secret) used for
 * sending events — this controller only stores which GA4 property to read
 * reports from, using the shared operator OAuth token.
 */
final class ShopGa4SettingApiController extends Controller
{
    public function __construct(
        private readonly VerifyGa4PropertyAccess $verifyGa4PropertyAccess,
    ) {}

    /**
     * Return the authenticated shop's current GA4 property configuration, if any.
     */
    public function show(Request $request): JsonResponse
    {
        $setting = $request->user()->ga4Setting;

        return response()->json([
            'setting' => $setting !== null ? $this->present($setting) : null,
        ]);
    }

    /**
     * Upsert the authenticated shop's GA4 property_id.
     *
     * Before persisting, verifies via the GA4 Admin API `properties.get`
     * endpoint (using the shared operator token — see VerifyGa4PropertyAccess)
     * that the shared operator Google account can actually access this
     * property. This doubles as validating existence AND access, and
     * populates property_display_name/property_timezone/property_currency
     * from the same successful lookup. Without this check, a merchant could
     * set any numeric property_id — including one belonging to a different
     * merchant's business — and read its GA4 metrics through the shared
     * operator token (cross-tenant exposure).
     *
     * Residual risk: this only proves the operator account can see the
     * property, not that it belongs to *this* merchant. See
     * VerifyGa4PropertyAccess for details — closing that gap needs an
     * operator-side approval step, out of scope here.
     */
    public function update(UpdateShopGa4PropertyRequest $request): JsonResponse
    {
        $propertyId = (string) $request->validated('property_id');

        try {
            $verified = $this->verifyGa4PropertyAccess->handle($propertyId);
        } catch (GoogleOAuthException $e) {
            Log::warning('ShopGa4SettingApiController: GA4 property verification failed', [
                'property_id' => $propertyId,
                'user_id' => $request->user()->getKey(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'This GA4 property could not be verified. Double-check the property ID '
                    .'and that the connected Google account has access to it.',
            ], 422);
        } catch (LockTimeoutException $e) {
            Log::warning('ShopGa4SettingApiController: token refresh lock timed out', [
                'property_id' => $propertyId,
                'user_id' => $request->user()->getKey(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'GA4 reporting is busy refreshing credentials. Please try again shortly.',
            ], 424);
        }

        $setting = ShopGa4Setting::query()->updateOrCreate(
            ['user_id' => $request->user()->getKey()],
            [
                'property_id' => $propertyId,
                'property_display_name' => $verified['display_name'],
                'property_timezone' => $verified['timezone'],
                'property_currency' => $verified['currency'],
                'active' => true,
                'last_verified_at' => now(),
            ],
        );

        return response()->json(['setting' => $this->present($setting)]);
    }

    /** @return array<string, mixed> */
    private function present(ShopGa4Setting $setting): array
    {
        return [
            'property_id' => $setting->property_id,
            'property_display_name' => $setting->property_display_name,
            'property_timezone' => $setting->property_timezone,
            'property_currency' => $setting->property_currency,
            'active' => $setting->active,
            'last_verified_at' => $setting->last_verified_at,
        ];
    }
}
