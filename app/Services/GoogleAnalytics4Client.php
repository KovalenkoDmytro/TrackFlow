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

    /**
     * Verify that the given credentials can reach the Measurement Protocol debug endpoint.
     *
     * Uses GA4's debug endpoint to validate measurement_id + api_secret.
     * The endpoint always returns HTTP 200 — auth failures surface as ERROR-level
     * validationMessages.
     *
     * @param  array<string, mixed>  $credentials  Must contain: measurement_id, api_secret.
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
    }

    /**
     * Persist ConversionActionMapping records for the standard GA4 e-commerce events.
     *
     * GA4 does not require remote provisioning of Key Events. This method only
     * creates the local ConversionActionMapping rows so ProcessTrackingEvent can
     * look up the GA4 event name at dispatch time.
     */
    public function setupConversionActions(PlatformIntegration $integration): void
    {
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
