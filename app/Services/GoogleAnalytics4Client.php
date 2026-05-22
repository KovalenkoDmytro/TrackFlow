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

    private const string COLLECT_URL = 'https://www.google-analytics.com/mp/collect';

    private const string DEBUG_URL = 'https://www.google-analytics.com/debug/mp/collect';

    private const string ADMIN_API_BASE = 'https://analyticsadmin.googleapis.com/v1beta';

    /**
     * Exchange a stored OAuth refresh token for a short-lived access token.
     *
     * @param  array<string, mixed>  $oauth  Must contain keys: client_id, client_secret, refresh_token.
     *
     * @throws \RuntimeException When Google's token endpoint rejects the request or returns no access_token.
     */
    private function getAccessToken(array $oauth): string
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $oauth['client_id'],
            'client_secret' => $oauth['client_secret'],
            'refresh_token' => $oauth['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);

        $data = $response->json();

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Failed to obtain access token from Google OAuth.');
        }

        return $data['access_token'];
    }

    /**
     * Verify that the given credentials can reach both the Measurement Protocol
     * and the GA4 Admin API.
     *
     * Step 1 uses GA4's debug endpoint to validate measurement_id + api_secret.
     * The endpoint always returns HTTP 200 — auth failures surface as ERROR-level
     * validationMessages. Step 2 exchanges the OAuth refresh token for an access
     * token and calls the Admin API key-events list endpoint to confirm Admin API
     * access.
     *
     * @param  array<string, mixed>  $credentials  Must contain: measurement_id, api_secret, property_id, oauth (array).
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

        $accessToken = $this->getAccessToken($credentials['oauth']);
        $propertyId = $credentials['property_id'];

        $adminResponse = Http::withToken($accessToken)
            ->get(self::ADMIN_API_BASE."/properties/{$propertyId}/keyEvents");

        $adminStatus = $adminResponse->status();

        if ($adminStatus < 200 || $adminStatus >= 300) {
            $errorMessage = $this->extractApiError($adminResponse->json(), $adminResponse->body());
            throw new \RuntimeException("GA4 Admin API error [{$adminStatus}]: {$errorMessage}");
        }
    }

    /**
     * Provision GA4 Key Events via the Admin API and persist ConversionActionMapping records.
     *
     * Calls the GA4 Admin API to create any missing Key Events for the standard
     * nine Shopify e-commerce events. Already-existing events are skipped (checked
     * via a prefetch list). 409 ALREADY_EXISTS responses are silently ignored as a
     * race-condition fallback. Non-fatal API errors are logged but do not abort the
     * loop so a single failure does not block the remaining events.
     *
     * After Admin API operations the method falls through to the local DB upsert
     * so ProcessTrackingEvent can look up the GA4 event name via ConversionActionMapping.
     */
    public function setupConversionActions(PlatformIntegration $integration): void
    {
        $credentials = json_decode((string) $integration->credentials, true) ?? [];
        $propertyId = $credentials['property_id'] ?? null;
        $oauth = $credentials['oauth'] ?? null;

        if ($propertyId !== null && is_array($oauth)) {
            try {
                $accessToken = $this->getAccessToken($oauth);
                $existingEventNames = $this->fetchExistingKeyEventNames($accessToken, $propertyId);

                foreach (self::GA4_EVENTS as $ga4EventName) {
                    if (in_array($ga4EventName, $existingEventNames, true)) {
                        continue;
                    }

                    $this->createKeyEvent($accessToken, $propertyId, $ga4EventName);
                }
            } catch (\Throwable $e) {
                Log::error('GoogleAnalytics4Client: Admin API setup failed', [
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
     * Return all existing GA4 Key Event names for the given property.
     *
     * @param  string  $accessToken  Short-lived OAuth access token.
     * @param  string  $propertyId  Numeric GA4 property ID.
     * @return array<string>
     *
     * @throws \RuntimeException When the Admin API returns a non-2xx response.
     */
    private function fetchExistingKeyEventNames(string $accessToken, string $propertyId): array
    {
        $response = Http::withToken($accessToken)
            ->get(self::ADMIN_API_BASE."/properties/{$propertyId}/keyEvents");

        $status = $response->status();

        if ($status < 200 || $status >= 300) {
            $errorMessage = $this->extractApiError($response->json(), $response->body());
            throw new \RuntimeException("GA4 Admin API error [{$status}]: {$errorMessage}");
        }

        $body = $response->json();
        $keyEvents = $body['keyEvents'] ?? [];

        return array_map(
            static fn (array $event) => $event['eventName'] ?? '',
            $keyEvents,
        );
    }

    /**
     * Create a single GA4 Key Event via the Admin API.
     *
     * 409 ALREADY_EXISTS responses are silently ignored — they occur when a
     * concurrent job created the event between our prefetch and this POST.
     * All other non-2xx responses are logged but not re-thrown so one failing
     * event does not abort the remaining events in the setup loop.
     *
     * @param  string  $accessToken  Short-lived OAuth access token.
     * @param  string  $propertyId  Numeric GA4 property ID.
     * @param  string  $eventName  Standard GA4 event name (e.g. "purchase").
     */
    private function createKeyEvent(string $accessToken, string $propertyId, string $eventName): void
    {
        $response = Http::withToken($accessToken)
            ->post(self::ADMIN_API_BASE."/properties/{$propertyId}/keyEvents", [
                'eventName' => $eventName,
                'countingMethod' => 'ONCE_PER_EVENT',
            ]);

        $status = $response->status();

        if ($status === 409) {
            return;
        }

        if ($status < 200 || $status >= 300) {
            $errorMessage = $this->extractApiError($response->json(), $response->body());
            Log::error('GoogleAnalytics4Client: failed to create key event', [
                'property_id' => $propertyId,
                'event_name' => $eventName,
                'status' => $status,
                'error' => $errorMessage,
            ]);
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
