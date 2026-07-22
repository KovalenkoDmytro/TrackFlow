<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\GoogleAds\CreateConversionActions;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\PlatformIntegration;
use App\Services\MetaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON API surface for the Meta (Facebook/Instagram) Conversions API settings page in the React SPA.
 *
 * Meta CAPI uses only a pixel_id + access_token pair (plus an optional test_event_code)
 * — no OAuth token exchange required. Mirrors GoogleAdsApiController's structure,
 * including its write-only sensitive-field pattern for access_token.
 */
final class MetaApiController extends Controller
{
    /**
     * Return the current Meta integration data for the authenticated shop.
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
            ->where('platform', Platform::Meta)
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
            'pixel_id' => $raw['pixel_id'] ?? '',
            'test_event_code' => $raw['test_event_code'] ?? '',
            // Access token is write-only: never echo the stored value back to the browser.
            'access_token' => '',
            'has_access_token' => ! empty($raw['access_token'] ?? null),
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
     * Validate, test, and persist Meta credentials for the authenticated shop.
     *
     * Validates credentials with MetaClient::testCredentials() before saving so
     * the merchant receives immediate feedback on invalid credentials. Dispatches
     * CreateConversionActions as a background job on success to persist the
     * event name mappings.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pixel_id' => ['required', 'string', 'regex:/^\d+$/'],
            'access_token' => ['nullable', 'string'],
            'test_event_code' => ['nullable', 'string'],
        ]);

        $shop = $request->user();

        $existing = $shop->platformIntegrations()
            ->where('platform', Platform::Meta)
            ->first();

        $existingRaw = $existing?->credentials !== null
            ? json_decode($existing->credentials, true)
            : [];

        $accessToken = self::resolveSensitiveField($validated['access_token'] ?? null, $existingRaw['access_token'] ?? null);

        if ($existing === null && $accessToken === '') {
            return response()->json([
                'error' => 'Access Token is required to connect Meta Conversions API for the first time.',
            ], 422);
        }

        $credentials = [
            'pixel_id' => trim($validated['pixel_id']),
            'access_token' => $accessToken,
            'test_event_code' => trim((string) ($validated['test_event_code'] ?? '')),
        ];

        try {
            app(MetaClient::class)->testCredentials($credentials);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => 'Could not connect to Meta: '.$e->getMessage(),
            ], 422);
        }

        $integration = PlatformIntegration::query()->updateOrCreate(
            [
                'user_id' => $shop->getKey(),
                'platform' => Platform::Meta,
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
     * Deactivate the Meta integration for the authenticated shop.
     *
     * Sets active = false rather than deleting so ConversionActionMapping records
     * are preserved and can be reactivated when the merchant reconnects.
     */
    public function destroy(Request $request): JsonResponse
    {
        $shop = $request->user();

        $shop->platformIntegrations()
            ->where('platform', Platform::Meta)
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
