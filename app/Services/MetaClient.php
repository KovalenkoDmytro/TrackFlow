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
     * Verify that the given credentials can reach the Meta Graph API.
     *
     * Performs a lightweight read request (fetch pixel id/name). Called before
     * credentials are persisted so the merchant sees an error immediately rather
     * than discovering the problem when the first event fires.
     *
     * @param  array<string, mixed>  $credentials  Must contain: pixel_id, access_token.
     *
     * @throws \RuntimeException When the API responds with a non-2xx status.
     */
    public function testCredentials(array $credentials): void
    {
        $response = Http::get(self::GRAPH_API_BASE.'/'.$credentials['pixel_id'], [
            'fields' => 'id,name',
            'access_token' => $credentials['access_token'],
        ]);

        if (! $response->successful()) {
            $status = $response->status();
            $errorMessage = $this->extractApiError($response->json(), $response->body());
            throw new \RuntimeException("Meta API error [{$status}]: {$errorMessage}");
        }
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
