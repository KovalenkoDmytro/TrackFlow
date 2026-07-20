<?php

declare(strict_types=1);

namespace App\Actions\Ga4;

use App\Exceptions\GoogleOAuthException;
use App\Services\Ga4TokenProvider;
use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Verifies that the single shared operator Google account (see
 * Ga4TokenProvider) can actually access a given GA4 property, by calling the
 * Admin API `properties.get` endpoint — the same Admin API surface
 * App\Services\GoogleAnalytics4Client already uses for keyEvents.
 *
 * This exists so ShopGa4SettingApiController cannot be used to bind a shop to
 * ANY numeric property_id sight-unseen: without this check, a merchant could
 * set another merchant's property_id and read their GA4 metrics through the
 * shared operator token (cross-tenant exposure). This call doubles as the
 * source of truth for property_display_name/property_timezone/
 * property_currency, so callers don't need a second Admin API round trip.
 *
 * Residual risk (out of scope here): this only proves the shared operator
 * account can see the property — it does NOT prove the property belongs to
 * *this* merchant's business. A merchant could still claim a property_id
 * belonging to a different merchant that the operator account happens to
 * have access to. Closing that gap needs an operator-side approval step.
 */
final class VerifyGa4PropertyAccess
{
    use AsObject;

    private const string ADMIN_API_BASE = 'https://analyticsadmin.googleapis.com/v1beta';

    public function __construct(
        private readonly Ga4TokenProvider $tokenProvider,
    ) {}

    /**
     * @return array{display_name: string|null, timezone: string|null, currency: string|null}
     *
     * @throws GoogleOAuthException When the shared operator account cannot access this property,
     *                              the property does not exist, or no operator account is connected.
     */
    public function handle(string $propertyId): array
    {
        $accessToken = $this->tokenProvider->getAccessToken();

        $response = Http::withToken($accessToken)
            ->get(self::ADMIN_API_BASE.'/properties/'.$propertyId);

        if (! $response->successful()) {
            $body = $response->json();
            $message = is_array($body) ? ($body['error']['message'] ?? json_encode($body)) : $response->body();

            throw new GoogleOAuthException(
                "The connected Google account cannot access GA4 property {$propertyId}: {$message}",
            );
        }

        $body = $response->json() ?? [];

        return [
            'display_name' => is_string($body['displayName'] ?? null) ? $body['displayName'] : null,
            'timezone' => is_string($body['timeZone'] ?? null) ? $body['timeZone'] : null,
            'currency' => is_string($body['currencyCode'] ?? null) ? $body['currencyCode'] : null,
        ];
    }
}
