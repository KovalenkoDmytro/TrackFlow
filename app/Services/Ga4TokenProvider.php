<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GoogleOAuthException;
use App\Models\OauthCredential;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Resolves a short-lived Google access token from the single shared
 * OauthCredential (provider "google") used by the GA4 Data API reporting
 * feature (see App\Services\Ga4ReportingClient).
 *
 * Access tokens are cached in Redis for their remaining lifetime (minus a
 * 60s safety margin) so most calls avoid the network round trip to Google's
 * token endpoint entirely. Refreshes are serialized via a distributed lock
 * to avoid a thundering herd of concurrent refresh requests all racing
 * against the same refresh_token.
 */
final class Ga4TokenProvider
{
    private const string TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const string CACHE_KEY = 'ga4:access_token:google';

    private const string LOCK_KEY = 'ga4:token-refresh:google';

    /**
     * Return a valid Google access token, refreshing it via the stored
     * refresh_token when the cached token is missing or expired.
     *
     * @throws GoogleOAuthException When no credential is connected, the
     *                              credential has been revoked, or the
     *                              refresh exchange with Google fails.
     */
    public function getAccessToken(): string
    {
        $cached = Cache::store('redis')->get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return Cache::store('redis')->lock(self::LOCK_KEY, 10)->block(5, function (): string {
            // Another process may have refreshed while we waited for the lock.
            $cached = Cache::store('redis')->get(self::CACHE_KEY);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            return $this->refresh();
        });
    }

    /**
     * @throws GoogleOAuthException
     */
    private function refresh(): string
    {
        $credential = OauthCredential::query()->where('provider', 'google')->first();

        if ($credential === null || $credential->revoked_at !== null || $credential->refresh_token === null) {
            throw new GoogleOAuthException(
                'No active Google OAuth credential is connected. An operator must connect '
                .'a Google account via /operator/google/start before GA4 reporting can be used.',
            );
        }

        $response = Http::asForm()->post(self::TOKEN_ENDPOINT, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $credential->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        $body = $response->json();

        if (! $response->successful() || empty($body['access_token'])) {
            $error = is_array($body) ? ($body['error'] ?? null) : null;

            if ($error === 'invalid_grant') {
                $credential->update(['revoked_at' => now()]);
            }

            $message = is_array($body)
                ? ($body['error_description'] ?? $body['error'] ?? json_encode($body))
                : $response->body();

            throw new GoogleOAuthException("Google OAuth token refresh failed: {$message}");
        }

        $accessToken = (string) $body['access_token'];
        $expiresIn = (int) ($body['expires_in'] ?? 3600);

        Cache::store('redis')->put(self::CACHE_KEY, $accessToken, max($expiresIn - 60, 60));

        $credential->update(['last_refreshed_at' => now()]);

        return $accessToken;
    }

    /**
     * Forget the cached access token so the next call is forced to refresh.
     * Used by Ga4ReportingClient after a 401 from the Data API.
     */
    public function forget(): void
    {
        Cache::store('redis')->forget(self::CACHE_KEY);
    }
}
