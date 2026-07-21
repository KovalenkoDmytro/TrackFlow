<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ConversionPlatformContract;
use App\Data\TrackingEventData;
use App\Models\ConversionActionMapping;
use App\Models\PlatformIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GA4 Measurement Protocol client implementing the ConversionPlatformContract.
 *
 * GA4 uses the Measurement Protocol — no OAuth, no API library required.
 * Credentials are a measurement_id + api_secret pair, which are passed as
 * query parameters on every request. The class is intentionally final —
 * extension happens through the contract, not inheritance.
 */
final class GoogleAnalytics4Client implements ConversionPlatformContract
{
    /**
     * Shopify e-commerce events mapped to their standard GA4 event names.
     *
     * GA4 does not require remote provisioning, but we persist these as
     * ConversionActionMapping records so ProcessTrackingEvent can look up
     * the GA4 event name at dispatch time via the same code path used by
     * all other platforms.
     *
     * @var array<string, string>
     */
    private const array GA4_EVENTS = [
        'purchase' => 'purchase',
        'add_to_cart' => 'add_to_cart',
        'begin_checkout' => 'begin_checkout',
        'view_item' => 'view_item',
        'search' => 'search',
        'view_cart' => 'view_cart',
        'add_payment_info' => 'add_payment_info',
        'add_shipping_info' => 'add_shipping_info',
        'remove_from_cart' => 'remove_from_cart',
    ];

    /**
     * GA4 Key Events to create via the Admin API (purchase is a GA4 default — skip it).
     *
     * @var array<int, string>
     */
    private const array KEY_EVENTS_TO_CREATE = [
        'add_to_cart',
        'begin_checkout',
        'view_item',
        'search',
        'view_cart',
        'add_payment_info',
        'add_shipping_info',
        'remove_from_cart',
    ];

    private const string COLLECT_URL = 'https://www.google-analytics.com/mp/collect';

    private const string DEBUG_URL = 'https://www.google-analytics.com/debug/mp/collect';

    private const string ADMIN_API_BASE = 'https://analyticsadmin.googleapis.com/v1beta';

    /**
     * Verify that the given credentials can reach the Measurement Protocol debug endpoint.
     *
     * Uses GA4's debug endpoint to validate measurement_id + api_secret.
     * The endpoint always returns HTTP 200 — auth failures surface as ERROR-level
     * validationMessages. When property_id and oauth credentials are also supplied,
     * Admin API access is verified by fetching the property's keyEvents list.
     *
     * @param  array<string, mixed>  $credentials  Must contain: measurement_id, api_secret.
     *                                             Optionally: property_id, oauth_refresh_token.
     *
     * @throws \RuntimeException When the API rejects the credentials or validation fails.
     */
    public function testCredentials(array $credentials): void
    {
        $response = Http::post(self::DEBUG_URL.'?'.http_build_query([
            'measurement_id' => $credentials['measurement_id'],
            'api_secret' => $credentials['api_secret'],
        ]), [
            'client_id' => 'test.validate',
            'events' => [['name' => 'page_view', 'params' => (object) []]],
        ]);

        $status = $response->status();

        if ($status !== 200) {
            $errorMessage = $this->extractApiError($response->json(), $response->body());
            throw new \RuntimeException("GA4 API error [{$status}]: {$errorMessage}");
        }

        $body = $response->json();
        $validationMessages = $body['validationMessages'] ?? [];

        $errors = array_filter(
            $validationMessages,
            static fn (array $msg) => ($msg['severity'] ?? '') === 'ERROR',
        );

        if (! empty($errors)) {
            $descriptions = array_map(
                static fn (array $msg) => $msg['description'] ?? json_encode($msg),
                array_values($errors),
            );

            throw new \RuntimeException('GA4 validation failed: '.implode('; ', $descriptions));
        }

        if (! empty($credentials['property_id']) && ! empty($credentials['oauth_refresh_token'])) {
            $accessToken = $this->getAccessToken((string) $credentials['oauth_refresh_token']);

            $adminResponse = Http::withToken($accessToken)
                ->get(self::ADMIN_API_BASE.'/properties/'.$credentials['property_id'].'/keyEvents');

            $adminStatus = $adminResponse->status();

            if ($adminStatus < 200 || $adminStatus >= 300) {
                $message = $this->extractApiError($adminResponse->json(), $adminResponse->body());
                throw new \RuntimeException("GA4 Admin API error [{$adminStatus}]: {$message}");
            }
        }
    }

    /**
     * Persist ConversionActionMapping records and optionally create GA4 Key Events via Admin API.
     *
     * Always upserts local ConversionActionMapping rows for all GA4_EVENTS so
     * ProcessTrackingEvent can look up the event name at dispatch time.
     *
     * When property_id and an oauth_refresh_token are present in the
     * integration's credentials, also creates Key Events in the GA4 property
     * for all events in KEY_EVENTS_TO_CREATE (purchase is skipped — it is a
     * GA4 default). Admin API failures are logged but do not throw so the job
     * is not retried.
     */
    public function setupConversionActions(PlatformIntegration $integration): void
    {
        /** @var array<string, mixed> $credentials */
        $credentials = json_decode((string) $integration->credentials, true) ?? [];

        if (! empty($credentials['property_id']) && ! empty($credentials['oauth_refresh_token'])) {
            try {
                $accessToken = $this->getAccessToken((string) $credentials['oauth_refresh_token']);
                $existing = $this->fetchExistingKeyEventNames($accessToken, (string) $credentials['property_id']);

                foreach (self::KEY_EVENTS_TO_CREATE as $eventName) {
                    if (in_array($eventName, $existing, true)) {
                        continue;
                    }

                    $this->createKeyEvent($accessToken, (string) $credentials['property_id'], $eventName);
                }
            } catch (\Throwable $e) {
                Log::error('GoogleAnalytics4Client: failed to create Admin API Key Events', [
                    'integration_id' => $integration->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach (self::GA4_EVENTS as $shopifyEvent => $ga4EventName) {
            try {
                ConversionActionMapping::query()->updateOrCreate(
                    [
                        'platform_integration_id' => $integration->getKey(),
                        'event' => $shopifyEvent,
                    ],
                    [
                        'external_action_id' => $ga4EventName,
                        'active' => true,
                    ],
                );
            } catch (\Throwable $e) {
                Log::error('GoogleAnalytics4Client: failed to upsert conversion action mapping', [
                    'integration_id' => $integration->getKey(),
                    'event' => $shopifyEvent,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send a single event to GA4 via the Measurement Protocol.
     *
     * Returns false (no retry) when the client ID is missing — without it GA4
     * cannot attribute the event to a session. Returns false on HTTP 400 (bad
     * request data). Throws on other non-2xx statuses so the job retries.
     *
     * GA4 Measurement Protocol returns HTTP 204 with no body on success.
     *
     * @param  array<string, mixed>  $credentials  Must contain: measurement_id, api_secret.
     * @param  TrackingEventData  $data  Event payload; gaClientId must be non-null for GA4.
     * @param  ConversionActionMapping  $mapping  The mapping row whose external_action_id is the GA4 event name.
     *
     * @throws \RuntimeException On network or authentication errors (job will retry).
     */
    public function uploadConversion(
        array $credentials,
        TrackingEventData $data,
        ConversionActionMapping $mapping,
    ): bool {
        if ($data->gaClientId === null) {
            return false;
        }

        $ga4EventName = $mapping->external_action_id;

        $params = [
            'currency' => $data->currency,
            'value' => $data->value,
        ];

        if ($ga4EventName === 'purchase' && $data->transactionId !== null) {
            $params['transaction_id'] = $data->transactionId;
        }

        $timestampMicros = (string) ($data->occurredAt->getTimestamp() * 1_000_000);

        $body = [
            'client_id' => $data->gaClientId,
            'timestamp_micros' => $timestampMicros,
            'events' => [
                [
                    'name' => $ga4EventName,
                    'params' => $params,
                ],
            ],
        ];

        $response = Http::post(self::COLLECT_URL.'?'.http_build_query([
            'measurement_id' => $credentials['measurement_id'],
            'api_secret' => $credentials['api_secret'],
        ]), $body);

        $status = $response->status();

        if ($status === 204) {
            return true;
        }

        if ($status === 400) {
            Log::warning('GoogleAnalytics4Client: bad request on uploadConversion (400)', [
                'ga4_event' => $ga4EventName,
                'body' => $response->body(),
            ]);

            return false;
        }

        $errorMessage = $this->extractApiError($response->json(), $response->body());
        throw new \RuntimeException("GA4 Measurement Protocol error [{$status}]: {$errorMessage}");
    }

    /**
     * Exchange a shop's refresh token for a short-lived access token via
     * Google's OAuth2 endpoint, using the app's single OAuth client
     * (config('services.google.client_id')/client_secret) — the client_id
     * and client_secret are the same for every shop; only the refresh_token
     * is per-shop.
     *
     * @throws \RuntimeException When the token exchange fails.
     */
    private function getAccessToken(string $refreshToken): string
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        $body = $response->json();

        if (! $response->successful() || empty($body['access_token'])) {
            Log::error('GoogleAnalytics4Client: OAuth token exchange failed', [
                'status' => $response->status(),
            ]);

            throw new \RuntimeException('GA4 OAuth token exchange failed.');
        }

        return (string) $body['access_token'];
    }

    /**
     * Retrieve all existing Key Event names for a GA4 property from the Admin API.
     *
     * @return array<int, string>
     *
     * @throws \RuntimeException On non-2xx response.
     */
    private function fetchExistingKeyEventNames(string $accessToken, string $propertyId): array
    {
        $response = Http::withToken($accessToken)
            ->get(self::ADMIN_API_BASE.'/properties/'.$propertyId.'/keyEvents');

        if (! $response->successful()) {
            $message = $this->extractApiError($response->json(), $response->body());
            throw new \RuntimeException("GA4 Admin API error [{$response->status()}]: {$message}");
        }

        $items = $response->json('keyEvents') ?? [];

        return array_map(
            static fn (array $item) => (string) ($item['eventName'] ?? ''),
            $items,
        );
    }

    /**
     * Create a single Key Event in a GA4 property via the Admin API.
     *
     * Silently ignores 409 Conflict (event already exists). Non-2xx responses
     * other than 409 are logged but do not throw — a single failed event must
     * not abort the rest of the setup.
     */
    private function createKeyEvent(string $accessToken, string $propertyId, string $eventName): void
    {
        $response = Http::withToken($accessToken)
            ->post(self::ADMIN_API_BASE.'/properties/'.$propertyId.'/keyEvents', [
                'eventName' => $eventName,
                'countingMethod' => 'ONCE_PER_EVENT',
            ]);

        $status = $response->status();

        if ($status === 409 || $response->successful()) {
            return;
        }

        $message = $this->extractApiError($response->json(), $response->body());
        Log::error('GoogleAnalytics4Client: failed to create Key Event', [
            'property_id' => $propertyId,
            'event_name' => $eventName,
            'status' => $status,
            'error' => $message,
        ]);
    }

    /**
     * Extract a human-readable error message from a GA4 API error response body.
     *
     * Falls back to JSON-encoding the full body so the raw payload is always
     * visible in logs. When the decoded body is null (empty or non-JSON response),
     * the raw body string is included, truncated to 200 characters.
     *
     * @param  array<string, mixed>|null  $responseBody  Decoded JSON response body.
     * @param  string  $rawBody  Raw response body string for fallback display.
     */
    private function extractApiError(?array $responseBody, string $rawBody = ''): string
    {
        if ($responseBody === null) {
            $preview = substr(trim($rawBody), 0, 200);

            return $preview !== ''
                ? "Unknown error (non-JSON response): {$preview}"
                : 'Unknown error (empty response body).';
        }

        $error = $responseBody['error'] ?? null;

        if (is_array($error)) {
            return $error['message'] ?? json_encode($error);
        }

        return json_encode($responseBody);
    }
}
