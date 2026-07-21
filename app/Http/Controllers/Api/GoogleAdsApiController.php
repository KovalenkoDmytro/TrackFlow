<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\GoogleAds\CreateConversionActions;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\PlatformIntegration;
use App\Services\GoogleAdsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON API surface for the Google Ads settings page in the React SPA.
 *
 * Mirrors the behaviour of GoogleAdsController but returns JSON responses
 * instead of Blade views and redirects, making it suitable for consumption
 * by the Inertia-free React frontend.
 */
final class GoogleAdsApiController extends Controller
{
    /**
     * Return the current Google Ads integration data for the authenticated shop.
     *
     * Returns an object with:
     *   - integration: nullable metadata (id, active, last_success_at, last_error, last_error_at)
     *   - credentials:  nullable decrypted credential fields (never includes raw json string)
     *   - mappings:     array of provisioned ConversionActionMapping records
     */
    public function show(Request $request): JsonResponse
    {
        $shop = $request->user();

        $integration = $shop->platformIntegrations()
            ->where('platform', Platform::GoogleAds)
            ->with('conversionActionMappings')
            ->first();

        if ($integration === null) {
            return response()->json([
                'integration' => null,
                'credentials' => null,
                'mappings' => [],
            ]);
        }

        $raw = $integration->credentials !== null
            ? json_decode($integration->credentials, true)
            : [];

        $credentials = [
            'customer_id' => $raw['customer_id'] ?? '',
            'mcc_id' => $raw['mcc_id'] ?? '',
            // Developer token and OAuth secrets are write-only: never echo the stored value back to the browser.
            'developer_token' => '',
            'oauth' => [
                'client_id' => '',
                'client_secret' => '',
                'refresh_token' => '',
            ],
            'has_developer_token' => ! empty($raw['developer_token'] ?? null),
            'has_oauth_client_id' => ! empty($raw['oauth']['client_id'] ?? null),
            'has_oauth_client_secret' => ! empty($raw['oauth']['client_secret'] ?? null),
            'has_oauth_refresh_token' => ! empty($raw['oauth']['refresh_token'] ?? null),
        ];

        return response()->json([
            'integration' => [
                'id' => $integration->getKey(),
                'active' => $integration->active,
                'last_success_at' => $integration->last_success_at,
                'last_error' => $integration->last_error,
                'last_error_at' => $integration->last_error_at,
            ],
            'credentials' => $credentials,
            'mappings' => $integration->conversionActionMappings->map(fn ($m) => [
                'id' => $m->getKey(),
                'event' => $m->event,
                'active' => $m->active,
                'external_action_id' => $m->external_action_id,
            ])->values()->all(),
        ]);
    }

    /**
     * Validate, test, and persist Google Ads credentials for the authenticated shop.
     *
     * Validates credentials with GoogleAdsClient::testCredentials() before saving
     * so the merchant receives immediate feedback on invalid credentials.
     * Dispatches CreateConversionActions as a background job on success.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'string', 'regex:/^\d{3}-?\d{3}-?\d{4}$/'],
            'developer_token' => ['nullable', 'string'],
            'mcc_id' => ['nullable', 'string', 'regex:/^\d{3}-?\d{3}-?\d{4}$/'],
            'oauth_client_id' => ['nullable', 'string'],
            'oauth_client_secret' => ['nullable', 'string'],
            'oauth_refresh_token' => ['nullable', 'string'],
        ]);

        $shop = $request->user();

        $existing = $shop->platformIntegrations()
            ->where('platform', Platform::GoogleAds)
            ->first();

        $existingRaw = $existing?->credentials !== null
            ? json_decode($existing->credentials, true)
            : [];
        $existingOauth = $existingRaw['oauth'] ?? [];

        $customerId = str_replace('-', '', $validated['customer_id']);
        $mccId = ! empty($validated['mcc_id']) ? str_replace('-', '', $validated['mcc_id']) : null;

        $developerToken = self::resolveSensitiveField($validated['developer_token'] ?? null, $existingRaw['developer_token'] ?? null);
        $clientId = self::resolveSensitiveField($validated['oauth_client_id'] ?? null, $existingOauth['client_id'] ?? null);
        $clientSecret = self::resolveSensitiveField($validated['oauth_client_secret'] ?? null, $existingOauth['client_secret'] ?? null);
        $refreshToken = self::resolveSensitiveField($validated['oauth_refresh_token'] ?? null, $existingOauth['refresh_token'] ?? null);

        if ($existing === null && ($developerToken === '' || $clientId === '' || $clientSecret === '' || $refreshToken === '')) {
            return response()->json([
                'error' => 'Developer Token, OAuth Client ID, Client Secret, and Refresh Token are all required to connect Google Ads for the first time.',
            ], 422);
        }

        $credentials = [
            'customer_id' => $customerId,
            'developer_token' => $developerToken,
            'mcc_id' => $mccId,
            'oauth' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
            ],
        ];

        try {
            app(GoogleAdsClient::class)->testCredentials($credentials);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => 'Could not connect to Google Ads: '.$e->getMessage(),
            ], 422);
        }

        $integration = PlatformIntegration::query()->updateOrCreate(
            [
                'user_id' => $shop->getKey(),
                'platform' => Platform::GoogleAds,
            ],
            [
                'active' => true,
                'credentials' => json_encode($credentials),
                'settings' => [],
            ],
        );

        CreateConversionActions::dispatch($integration);

        return response()->json([
            'success' => true,
            'integration' => [
                'id' => $integration->getKey(),
                'active' => $integration->active,
            ],
        ]);
    }

    /**
     * Deactivate the Google Ads integration for the authenticated shop.
     *
     * Sets active = false rather than deleting so ConversionActionMapping records
     * are preserved and can be reactivated when the merchant reconnects.
     */
    public function destroy(Request $request): JsonResponse
    {
        $shop = $request->user();

        $shop->platformIntegrations()
            ->where('platform', Platform::GoogleAds)
            ->update(['active' => false]);

        return response()->json(['success' => true]);
    }

    /**
     * Resolve a write-only credential field: use the newly submitted value when
     * provided (non-blank), otherwise fall back to the value already stored for
     * this integration so resaving the form without retyping secrets does not
     * wipe them.
     */
    private static function resolveSensitiveField(?string $submitted, ?string $existing): string
    {
        $trimmed = trim((string) $submitted);

        return $trimmed !== '' ? $trimmed : trim((string) $existing);
    }
}
