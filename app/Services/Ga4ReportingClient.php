<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the GA4 Data API `runReport` endpoint.
 *
 * Authenticates with the access token resolved by Ga4TokenProvider, which is
 * backed by the single shared operator OauthCredential (provider "google") —
 * there is no per-shop OAuth here, only a per-shop property_id (see
 * App\Models\ShopGa4Setting and App\Actions\Ga4\FetchGa4Report).
 */
final class Ga4ReportingClient
{
    private const string DATA_API_BASE = 'https://analyticsdata.googleapis.com/v1beta';

    public function __construct(
        private readonly Ga4TokenProvider $tokenProvider,
    ) {}

    /**
     * Run a GA4 Data API report for the given property.
     *
     * On a 401 (expired/invalid cached access token) the cached token is
     * invalidated and the request is retried exactly once with a freshly
     * refreshed token before the error is surfaced to the caller.
     *
     * @param  array<string, mixed>  $params  runReport request body (dateRanges, metrics, dimensions, etc.).
     * @return array<string, mixed> Decoded JSON response.
     *
     * @throws \RuntimeException On a non-2xx response after the retry.
     */
    public function runReport(string $propertyId, array $params): array
    {
        $response = $this->request($propertyId, $params);

        if ($response->status() === 401) {
            $this->tokenProvider->forget();
            $response = $this->request($propertyId, $params);
        }

        if (! $response->successful()) {
            $body = $response->json();
            $message = is_array($body) ? ($body['error']['message'] ?? json_encode($body)) : $response->body();

            throw new \RuntimeException("GA4 Data API error [{$response->status()}]: {$message}");
        }

        return $response->json() ?? [];
    }

    private function request(string $propertyId, array $params): Response
    {
        $accessToken = $this->tokenProvider->getAccessToken();

        return Http::withToken($accessToken)
            ->post(self::DATA_API_BASE."/properties/{$propertyId}:runReport", $params);
    }
}
