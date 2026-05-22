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
 * Google Ads REST API client implementing the ConversionPlatformContract.
 *
 * Responsible for all Google Ads API communication: OAuth token exchange,
 * conversion action provisioning, and click-conversion uploads.
 * The class is intentionally final — extension happens through the contract,
 * not inheritance.
 */
final class GoogleAdsClient implements ConversionPlatformContract
{
    /**
     * The nine Shopify e-commerce events TrackFlow tracks, mapped to their
     * Google Ads conversion category. These are provisioned once per integration
     * via setupConversionActions().
     *
     * @var array<string, string>
     */
    private const array EVENT_CATEGORIES = [
        'search' => 'PAGE_VIEW',
        'view_item' => 'PAGE_VIEW',
        'add_to_cart' => 'ADD_TO_CART',
        'view_cart' => 'PAGE_VIEW',
        'remove_from_cart' => 'PAGE_VIEW',
        'begin_checkout' => 'BEGIN_CHECKOUT',
        'add_shipping_info' => 'PAGE_VIEW',
        'add_payment_info' => 'PAGE_VIEW',
        'purchase' => 'PURCHASE',
    ];

    /**
     * Exchange a stored OAuth refresh token for a short-lived access token.
     *
     * @param  array<string, mixed>  $oauth  Must contain keys: client_id, client_secret, refresh_token.
     *
     * @throws \RuntimeException When Google's token endpoint rejects the request or returns no access_token.
     */
    public function getAccessToken(array $oauth): string
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
     * Verify that the given credentials can authenticate against the Google Ads API.
     *
     * Performs a lightweight read request (list conversion actions). Called before
     * credentials are persisted so the merchant sees an error immediately rather
     * than discovering the problem when the first event fires.
     *
     * @param  array<string, mixed>  $credentials  Decrypted credentials from PlatformIntegration.
     *                                             Expected keys: oauth (array), customer_id, developer_token, mcc_id (optional).
     *
     * @throws \RuntimeException When the API responds with a non-2xx status.
     */
    public function testCredentials(array $credentials): void
    {
        $accessToken = $this->getAccessToken($credentials['oauth']);
        $customerId = str_replace('-', '', $credentials['customer_id']);

        $response = Http::withHeaders($this->buildHeaders($accessToken, $credentials))
            ->post(
                "https://googleads.googleapis.com/v24/customers/{$customerId}/googleAds:search",
                [
                    'query' => 'SELECT customer.id FROM customer LIMIT 1',
                    'pageSize' => 1,
                ],
            );

        if (! $response->successful()) {
            $status = $response->status();
            $errorMessage = $this->extractApiError($response->json(), $response->body());
            throw new \RuntimeException("Google Ads API error [{$status}]: {$errorMessage}");
        }
    }

    /**
     * Provision all nine TrackFlow conversion actions on Google Ads and persist the mappings.
     *
     * Iterates EVENT_CATEGORIES, creates each conversion action via the API, and
     * upserts a ConversionActionMapping so ProcessTrackingEvent can look up the
     * resource name at dispatch time. Individual action failures are logged and
     * skipped so a single bad event name does not abort the entire setup.
     *
     * @throws \RuntimeException When credential decryption or the OAuth exchange fails before any action is attempted.
     */
    public function setupConversionActions(PlatformIntegration $integration): void
    {
        $credentials = json_decode($integration->credentials, true);

        foreach (self::EVENT_CATEGORIES as $event => $category) {
            try {
                $resourceName = $this->createConversionAction($credentials, $event, $category);

                ConversionActionMapping::updateOrCreate(
                    ['platform_integration_id' => $integration->getKey(), 'event' => $event],
                    ['external_action_id' => $resourceName, 'active' => true],
                );
            } catch (\RuntimeException $e) {
                Log::error('GoogleAdsClient: failed to create conversion action', [
                    'integration_id' => $integration->getKey(),
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Create a single conversion action on Google Ads and return its resource name.
     *
     * The action is created with UPLOAD_CLICKS type and
     * GOOGLE_SEARCH_ATTRIBUTION_LAST_CLICK attribution model. The name is prefixed
     * with "TF - " to identify TrackFlow-managed actions in the Google Ads UI.
     *
     * @param  array<string, mixed>  $credentials  Decrypted credentials (same shape as testCredentials).
     * @param  string  $name  Shopify event name used as the action label (e.g. "purchase").
     * @param  string  $category  Google Ads conversion category (e.g. "PURCHASE", "ADD_TO_CART").
     * @return string The resource name of the newly created conversion action (e.g. "customers/123/conversionActions/456").
     *
     * @throws \RuntimeException When the API call fails or returns no resource name.
     */
    public function createConversionAction(array $credentials, string $name, string $category = 'DEFAULT'): string
    {
        $accessToken = $this->getAccessToken($credentials['oauth']);
        $customerId = str_replace('-', '', $credentials['customer_id']);

        $body = [
            'operations' => [
                [
                    'create' => [
                        'name' => "TF - {$name}",
                        'type' => 'UPLOAD_CLICKS',
                        'category' => $category,
                        'status' => 'ENABLED',
                        'attribution_model_settings' => [
                            'attribution_model' => 'GOOGLE_SEARCH_ATTRIBUTION_LAST_CLICK',
                        ],
                    ],
                ],
            ],
        ];

        $response = Http::withHeaders($this->buildHeaders($accessToken, $credentials))
            ->post("https://googleads.googleapis.com/v24/customers/{$customerId}/conversionActions:mutate", $body);

        if (! $response->successful()) {
            $status = $response->status();
            $errorMessage = $this->extractApiError($response->json(), $response->body());
            throw new \RuntimeException("Failed to create conversion action [{$status}]: {$errorMessage}");
        }

        $data = $response->json();
        $resourceName = $data['results'][0]['resourceName'] ?? null;

        if (empty($resourceName)) {
            throw new \RuntimeException('Google Ads did not return a resource name for the created conversion action.');
        }

        return $resourceName;
    }

    /**
     * Send a single conversion event to Google Ads via the uploadClickConversions endpoint.
     *
     * The request is sent with partial_failure=true so Google Ads accepts the
     * batch even if individual conversions are invalid. A non-empty
     * partialFailureError in the response indicates the conversion was rejected
     * but the HTTP call itself succeeded — the method returns false in that case
     * so the caller can record a soft failure without triggering a job retry.
     *
     * @param  array<string, mixed>  $credentials  Decrypted credentials.
     * @param  TrackingEventData  $data  The event payload including gclid, value, currency, etc.
     * @param  ConversionActionMapping  $mapping  The mapping row that holds the Google Ads resource name.
     * @return bool True when Google Ads accepted the conversion; false on partial failure.
     *
     * @throws \RuntimeException On network errors or non-2xx HTTP responses (job will retry).
     */
    public function uploadConversion(
        array $credentials,
        TrackingEventData $data,
        ConversionActionMapping $mapping,
    ): bool {
        return $this->uploadClickConversion($credentials, $mapping->external_action_id, $data);
    }

    /**
     * Low-level click-conversion upload used by uploadConversion().
     *
     * Kept as a named method (rather than inlined) so it remains independently
     * testable and callable from places that already hold the resource name string.
     *
     * @param  array<string, mixed>  $credentials  Decrypted credentials.
     * @param  string  $conversionActionResourceName  Google Ads resource name from ConversionActionMapping.
     * @param  TrackingEventData  $data  The event payload.
     *
     * @throws \RuntimeException On non-2xx HTTP response.
     */
    public function uploadClickConversion(array $credentials, string $conversionActionResourceName, TrackingEventData $data): bool
    {
        $accessToken = $this->getAccessToken($credentials['oauth']);
        $customerId = str_replace('-', '', $credentials['customer_id']);

        $conversion = [
            'gclid' => $data->gclid,
            'conversion_action' => $conversionActionResourceName,
            'conversion_date_time' => $data->occurredAt->format('Y-m-d H:i:sP'),
            'conversion_value' => $data->value,
            'currency_code' => $data->currency,
            'order_id' => $data->transactionId,
        ];

        $conversion = array_filter($conversion, fn (mixed $v) => $v !== null);

        $body = [
            'conversions' => [$conversion],
            'partial_failure' => true,
        ];

        $response = Http::withHeaders($this->buildHeaders($accessToken, $credentials))
            ->post("https://googleads.googleapis.com/v24/customers/{$customerId}:uploadClickConversions", $body);

        if (! $response->successful()) {
            $status = $response->status();
            $errorMessage = $this->extractApiError($response->json(), $response->body());
            throw new \RuntimeException("Failed to upload click conversion [{$status}]: {$errorMessage}");
        }

        $result = $response->json();

        if (! empty($result['partialFailureError'])) {
            Log::warning('GoogleAdsClient: partial failure on uploadClickConversion', [
                'partial_failure_error' => $result['partialFailureError'],
                'conversion_action' => $conversionActionResourceName,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Build the HTTP headers required by every Google Ads API request.
     *
     * Conditionally includes login-customer-id when an MCC (manager) account ID
     * is present in credentials — required when the customer account is managed
     * under an MCC hierarchy.
     *
     * @param  string  $accessToken  Short-lived OAuth access token.
     * @param  array<string, mixed>  $credentials  Must contain developer_token; optionally mcc_id.
     * @return array<string, string>
     */
    private function buildHeaders(string $accessToken, array $credentials): array
    {
        $headers = [
            'Authorization' => "Bearer {$accessToken}",
            'developer-token' => $credentials['developer_token'],
            'Content-Type' => 'application/json',
        ];

        $mccId = isset($credentials['mcc_id']) ? str_replace('-', '', (string) $credentials['mcc_id']) : '';

        if ($mccId !== '') {
            $headers['login-customer-id'] = $mccId;
        }

        return $headers;
    }

    /**
     * Extract a human-readable error message from a Google Ads API error response body.
     *
     * Google Ads wraps errors in an "error" key with a "message" sub-key. Falls
     * back to JSON-encoding the full body so the raw payload is always visible in logs.
     * When the decoded body is null (e.g. empty or non-JSON response), the raw body
     * string is included in the message, truncated to 200 characters.
     *
     * @param  array<string, mixed>|null  $responseBody  Decoded JSON response body.
     * @param  string|null  $rawBody  Raw response body string for fallback display.
     */
    private function extractApiError(?array $responseBody, ?string $rawBody = null): string
    {
        if ($responseBody === null) {
            $preview = $rawBody !== null ? substr(trim($rawBody), 0, 200) : '';

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
