<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\GoogleAds\CreateConversionActions;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\PlatformIntegration;
use App\Services\GoogleAnalytics4Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON API surface for the GA4 settings page in the React SPA.
 *
 * GA4 credentials include a Measurement Protocol pair (measurement_id + api_secret)
 * and Admin API credentials (property_id + oauth) used to provision Key Events.
 * Mirrors GoogleAdsApiController's structure.
 */
final class Ga4ApiController extends Controller
{
    /**
     * Return the current GA4 integration status and credentials for the authenticated shop.
     *
     * Returns an object with:
     *   - connected: bool — whether an active integration exists
     *   - credentials: decrypted credential fields (empty strings when not connected)
     */
    public function show(Request $request): JsonResponse
    {
        $shop = $request->user();

        $integration = $shop->platformIntegrations()
            ->where('platform', Platform::GoogleAnalytics4)
            ->first();

        if ($integration === null) {
            return response()->json([
                'connected' => false,
                'credentials' => [
                    'measurement_id' => '',
                    'api_secret' => '',
                    'property_id' => '',
                    'oauth_client_id' => '',
                    'oauth_client_secret' => '',
                    'oauth_refresh_token' => '',
                ],
            ]);
        }

        $raw = $integration->credentials !== null
            ? json_decode($integration->credentials, true)
            : [];

        return response()->json([
            'connected' => (bool) $integration->active,
            'credentials' => [
                'measurement_id' => $raw['measurement_id'] ?? '',
                'api_secret' => $raw['api_secret'] ?? '',
                'property_id' => $raw['property_id'] ?? '',
                'oauth_client_id' => $raw['oauth']['client_id'] ?? '',
                'oauth_client_secret' => $raw['oauth']['client_secret'] ?? '',
                'oauth_refresh_token' => $raw['oauth']['refresh_token'] ?? '',
            ],
        ]);
    }

    /**
     * Validate, test, and persist GA4 credentials for the authenticated shop.
     *
     * Validates credentials with GoogleAnalytics4Client::testCredentials() before
     * saving so the merchant receives immediate feedback on invalid credentials.
     * Dispatches CreateConversionActions as a background job on success to provision
     * GA4 Key Events via the Admin API and persist the standard event name mappings.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'measurement_id' => ['required', 'string'],
            'api_secret' => ['required', 'string'],
            'property_id' => ['required', 'string', 'regex:/^\d+$/'],
            'oauth_client_id' => ['required', 'string'],
            'oauth_client_secret' => ['required', 'string'],
            'oauth_refresh_token' => ['required', 'string'],
        ]);

        $credentials = [
            'measurement_id' => trim($validated['measurement_id']),
            'api_secret' => trim($validated['api_secret']),
            'property_id' => trim($validated['property_id']),
            'oauth' => [
                'client_id' => trim($validated['oauth_client_id']),
                'client_secret' => trim($validated['oauth_client_secret']),
                'refresh_token' => trim($validated['oauth_refresh_token']),
            ],
        ];

        try {
            app(GoogleAnalytics4Client::class)->testCredentials($credentials);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => 'Could not connect to GA4: '.$e->getMessage(),
            ], 422);
        }

        $shop = $request->user();

        $integration = PlatformIntegration::query()->updateOrCreate(
            [
                'user_id' => $shop->getKey(),
                'platform' => Platform::GoogleAnalytics4,
            ],
            [
                'active' => true,
                'credentials' => json_encode($credentials),
                'settings' => [],
            ],
        );

        CreateConversionActions::dispatch($integration);

        return response()->json(['message' => 'Connected successfully']);
    }

    /**
     * Deactivate the GA4 integration for the authenticated shop.
     *
     * Sets active = false rather than deleting so ConversionActionMapping records
     * are preserved and can be reactivated when the merchant reconnects.
     */
    public function destroy(Request $request): JsonResponse
    {
        $shop = $request->user();

        $shop->platformIntegrations()
            ->where('platform', Platform::GoogleAnalytics4)
            ->update(['active' => false]);

        return response()->json(['message' => 'Disconnected successfully']);
    }
}
