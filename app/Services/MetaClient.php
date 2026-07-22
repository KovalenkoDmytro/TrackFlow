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
 * Meta (Facebook/Instagram) Conversions API client implementing the ConversionPlatformContract.
 *
 * Meta CAPI uses a pixel_id + access_token pair — no OAuth token exchange required.
 * Standard events are recognised automatically by Meta once sent, so no remote
 * provisioning step exists (unlike Google Ads conversion actions). The class is
 * intentionally final — extension happens through the contract, not inheritance.
 */
final class MetaClient implements ConversionPlatformContract
{
    /**
     * Shopify e-commerce events mapped to their Meta event names.
     *
     * Events with a direct Meta standard-event equivalent use that name so Meta
     * can apply standard-event optimisations. Events without one (view_cart,
     * remove_from_cart, add_shipping_info) use custom event names verbatim —
     * Meta accepts arbitrary custom event_name strings, they simply won't
     * receive standard-event treatment.
     *
     * @var array<string, string>
     */
    private const array EVENT_MAP = [
        'purchase' => 'Purchase',
        'add_to_cart' => 'AddToCart',
        'begin_checkout' => 'InitiateCheckout',
        'view_item' => 'ViewContent',
        'search' => 'Search',
        'add_payment_info' => 'AddPaymentInfo',
        'view_cart' => 'ViewCart',
        'remove_from_cart' => 'RemoveFromCart',
        'add_shipping_info' => 'AddShippingInfo',
    ];

    private const string GRAPH_API_BASE = 'https://graph.facebook.com/v21.0';

    /**
     * Distinctive custom event name used to validate credentials without polluting
     * the merchant's real conversion funnel. Meta will never attribute this name to
     * a standard e-commerce event or optimisation, so it is safe to send as a real
     * (non-test-mode) event even without a test_event_code.
     */
    private const string CONNECTION_TEST_EVENT_NAME = 'TrackFlowConnectionTest';

    /**
     * Verify that the given credentials can reach the Meta Graph API.
     *
     * Sends a lightweight, non-attributing event to the Conversions API events
     * endpoint (POST /{pixel_id}/events) rather than reading the pixel via the
     * Marketing API. Meta's documented flow for generating a Conversions API
     * token (Events Manager -> Data Sources -> Pixel -> Settings -> Conversions
     * API -> Generate an Access Token) issues a narrow-scope token
     * ("read_ads_dataset_quality", granular to that pixel) that cannot read pixel
     * metadata via GET but can post events — so validation must match the
     * capability MetaClient actually needs. Called before credentials are
     * persisted so the merchant sees an error immediately rather than discovering
     * the problem when the first event fires.
     *
     * @param  array<string, mixed>  $credentials  Must contain: pixel_id, access_token. Optionally: test_event_code.
     *
     * @throws \RuntimeException With a merchant-actionable message when the API responds with a non-2xx status.
     */
    public function testCredentials(array $credentials): void
    {
        $event = [
            'event_name' => self::CONNECTION_TEST_EVENT_NAME,
            'event_time' => now()->getTimestamp(),
            'action_source' => 'system_generated',
            'user_data' => [
                'client_ip_address' => '127.0.0.1',
                'client_user_agent' => 'TrackFlow-ConnectionTest/1.0',
            ],
        ];

        $body = ['data' => [$event]];

        if (! empty($credentials['test_event_code'])) {
            $body['test_event_code'] = $credentials['test_event_code'];
        }

        $response = Http::post(self::GRAPH_API_BASE."/{$credentials['pixel_id']}/events?".http_build_query([
            'access_token' => $credentials['access_token'],
        ]), $body);

        if (! $response->successful()) {
            throw new \RuntimeException($this->buildTestCredentialsErrorMessage($response->json(), $response->body()));
        }
    }

    /**
     * Build a merchant-facing, actionable error message for a failed testCredentials() call.
     *
     * Meta's Graph API error code 100 ("Invalid parameter" / "Missing Permission")
     * here most commonly means the pixel_id is wrong (the token has no access to
     * that dataset at all) or the token has been generated with genuinely no
     * Conversions API permission. Every other error code falls back to a generic
     * message that still surfaces Meta's raw reason so the merchant (or a
     * developer helping them) can diagnose it.
     *
     * @param  array<string, mixed>|null  $responseBody  Decoded JSON response body.
     * @param  string  $rawBody  Raw response body string, used when the body isn't valid JSON.
     */
    private function buildTestCredentialsErrorMessage(?array $responseBody, string $rawBody): string
    {
        $error = $responseBody['error'] ?? null;
        $rawReason = $this->extractApiError($responseBody, $rawBody);

        if (is_array($error) && (int) ($error['code'] ?? 0) === 100) {
            return 'Meta rejected this access token: it does not have permission to send events for this Pixel. '
                .'Double-check the Pixel ID, or generate a new access token from Events Manager -> Data Sources -> '
                .'Pixel -> Settings -> Conversions API, then try again. '
                ."(Meta says: {$rawReason})";
        }

        return "Meta rejected the connection: {$rawReason}. "
            .'Double-check the Pixel ID and that the access token has not expired.';
    }

    /**
     * Persist ConversionActionMapping records for every Shopify event.
     *
     * Meta does not require remote provisioning — standard and custom events are
     * recognised automatically once sent via uploadConversion(). This method only
     * upserts local ConversionActionMapping rows so ProcessTrackingEvent can look
     * up the Meta event name at dispatch time via the same code path used by all
     * other platforms.
     */
    public function setupConversionActions(PlatformIntegration $integration): void
    {
        foreach (self::EVENT_MAP as $shopifyEvent => $metaEventName) {
            try {
                ConversionActionMapping::query()->updateOrCreate(
                    [
                        'platform_integration_id' => $integration->getKey(),
                        'event' => $shopifyEvent,
                    ],
                    [
                        'external_action_id' => $metaEventName,
                        'active' => true,
                    ],
                );
            } catch (\Throwable $e) {
                Log::error('MetaClient: failed to upsert conversion action mapping', [
                    'integration_id' => $integration->getKey(),
                    'event' => $shopifyEvent,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send a single event to Meta via the Conversions API.
     *
     * Returns false (no retry) when there is no fbp, fbc, or IP to attribute the
     * event to — without at least one of these Meta cannot match the event to a
     * user. Uses transactionId as the event_id for deduplication with the Meta
     * Pixel's browser-side events when present, otherwise falls back to a stable
     * hash of the event so retries of the same job do not create duplicate events.
     *
     * @param  array<string, mixed>  $credentials  Must contain: pixel_id, access_token. Optionally: test_event_code.
     * @param  TrackingEventData  $data  Event payload.
     * @param  ConversionActionMapping  $mapping  The mapping row whose external_action_id is the Meta event name.
     *
     * @throws \RuntimeException On non-2xx HTTP response (job will retry).
     */
    public function uploadConversion(
        array $credentials,
        TrackingEventData $data,
        ConversionActionMapping $mapping,
    ): bool {
        if ($data->fbp === null && $data->fbc === null && $data->ip === null) {
            return false;
        }

        $metaEventName = $mapping->external_action_id;

        $userData = array_filter([
            'client_ip_address' => $data->ip,
            'client_user_agent' => $data->userAgent,
            'fbp' => $data->fbp,
            'fbc' => $data->fbc,
        ], static fn (?string $v) => $v !== null);

        $customData = array_filter([
            'currency' => $data->currency,
            'value' => $data->value,
        ], static fn (mixed $v) => $v !== null);

        $eventId = $data->transactionId ?? md5(
            $metaEventName.'|'.$data->occurredAt->format(\DateTimeInterface::ATOM).'|'.($data->idempotencyKey ?? ''),
        );

        $event = [
            'event_name' => $metaEventName,
            'event_time' => $data->occurredAt->getTimestamp(),
            'action_source' => 'website',
            'event_id' => $eventId,
            'user_data' => $userData,
            'custom_data' => $customData,
        ];

        $body = ['data' => [$event]];

        if (! empty($credentials['test_event_code'])) {
            $body['test_event_code'] = $credentials['test_event_code'];
        }

        $response = Http::post(self::GRAPH_API_BASE."/{$credentials['pixel_id']}/events?".http_build_query([
            'access_token' => $credentials['access_token'],
        ]), $body);

        if ($response->successful()) {
            return true;
        }

        $status = $response->status();
        $errorMessage = $this->extractApiError($response->json(), $response->body());
        throw new \RuntimeException("Meta CAPI error [{$status}]: {$errorMessage}");
    }

    /**
     * Extract a human-readable error message from a Meta Graph API error response body.
     *
     * Meta wraps errors in an "error" key with a "message" sub-key. Falls back to
     * JSON-encoding the full body so the raw payload is always visible in logs.
     * When the decoded body is null (empty or non-JSON response), the raw body
     * string is included, truncated to 200 characters.
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
