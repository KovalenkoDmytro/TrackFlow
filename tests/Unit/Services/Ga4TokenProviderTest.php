<?php

declare(strict_types=1);

use App\Exceptions\GoogleOAuthException;
use App\Models\OauthCredential;
use App\Services\Ga4TokenProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

mutates(Ga4TokenProvider::class);

/**
 * Swaps the "redis" cache store to the array driver so these tests exercise
 * the exact same Cache::store('redis') code path as production without
 * requiring a real Redis connection.
 */
beforeEach(function (): void {
    config(['cache.stores.redis' => ['driver' => 'array']]);
});

describe('Ga4TokenProvider::getAccessToken', function (): void {
    it('throws when no Google OauthCredential has ever been connected', function (): void {
        expect(fn () => (new Ga4TokenProvider)->getAccessToken())
            ->toThrow(GoogleOAuthException::class);
    });

    it('throws when the credential has been revoked', function (): void {
        OauthCredential::query()->create([
            'provider' => 'google',
            'refresh_token' => 'stale-refresh-token',
            'revoked_at' => now(),
        ]);

        expect(fn () => (new Ga4TokenProvider)->getAccessToken())
            ->toThrow(GoogleOAuthException::class);
    });

    it('throws when the credential has no refresh_token stored', function (): void {
        OauthCredential::query()->create([
            'provider' => 'google',
            'refresh_token' => null,
        ]);

        expect(fn () => (new Ga4TokenProvider)->getAccessToken())
            ->toThrow(GoogleOAuthException::class);
    });

    it('returns the cached access token without making any HTTP call', function (): void {
        Cache::store('redis')->put('ga4:access_token:google', 'already-cached-token', 300);
        Http::fake();

        $token = (new Ga4TokenProvider)->getAccessToken();

        expect($token)->toBe('already-cached-token');
        Http::assertNothingSent();
    });

    it('exchanges the refresh_token for a new access token on a cache miss and caches it', function (): void {
        $credential = OauthCredential::query()->create([
            'provider' => 'google',
            'refresh_token' => 'stored-refresh-token',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'brand-new-access-token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $token = (new Ga4TokenProvider)->getAccessToken();

        expect($token)->toBe('brand-new-access-token');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['grant_type'] === 'refresh_token'
                && $request['refresh_token'] === 'stored-refresh-token'
                && $request['client_id'] === config('services.google.client_id')
                && $request['client_secret'] === config('services.google.client_secret');
        });

        expect(Cache::store('redis')->get('ga4:access_token:google'))->toBe('brand-new-access-token');
        expect($credential->refresh()->last_refreshed_at)->not->toBeNull();
    });

    it('caches the access token with a TTL of expires_in minus the 60s safety margin', function (): void {
        OauthCredential::query()->create([
            'provider' => 'google',
            'refresh_token' => 'stored-refresh-token',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'token-with-known-ttl',
                'expires_in' => 3600,
            ], 200),
        ]);

        (new Ga4TokenProvider)->getAccessToken();

        $entries = Cache::store('redis')->getStore()->all();
        $ttl = $entries['ga4:access_token:google']['expiresAt'] - now()->getTimestamp();

        expect($ttl)->toBeGreaterThan(3530)->toBeLessThanOrEqual(3541);
    });

    it('floors the cache TTL at 60 seconds when expires_in is smaller than the safety margin', function (): void {
        OauthCredential::query()->create([
            'provider' => 'google',
            'refresh_token' => 'stored-refresh-token',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'short-lived-token',
                'expires_in' => 30,
            ], 200),
        ]);

        (new Ga4TokenProvider)->getAccessToken();

        $entries = Cache::store('redis')->getStore()->all();
        $ttl = $entries['ga4:access_token:google']['expiresAt'] - now()->getTimestamp();

        expect($ttl)->toBeGreaterThan(50)->toBeLessThanOrEqual(61);
    });

    it('reuses the cached token on a second call instead of hitting the network again', function (): void {
        OauthCredential::query()->create([
            'provider' => 'google',
            'refresh_token' => 'stored-refresh-token',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'only-fetched-once',
                'expires_in' => 3600,
            ], 200),
        ]);

        $provider = new Ga4TokenProvider;
        $first = $provider->getAccessToken();
        $second = $provider->getAccessToken();

        expect($first)->toBe($second)->toBe('only-fetched-once');
        Http::assertSentCount(1);
    });

    it('marks the credential as revoked and throws when Google responds with invalid_grant', function (): void {
        $credential = OauthCredential::query()->create([
            'provider' => 'google',
            'refresh_token' => 'revoked-on-googles-side',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ], 400),
        ]);

        expect(fn () => (new Ga4TokenProvider)->getAccessToken())
            ->toThrow(GoogleOAuthException::class);

        expect($credential->refresh()->revoked_at)->not->toBeNull();
    });

    it('throws without revoking the credential on a transient (non-invalid_grant) failure', function (): void {
        $credential = OauthCredential::query()->create([
            'provider' => 'google',
            'refresh_token' => 'still-good-refresh-token',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'server_error'], 500),
        ]);

        expect(fn () => (new Ga4TokenProvider)->getAccessToken())
            ->toThrow(GoogleOAuthException::class);

        expect($credential->refresh()->revoked_at)->toBeNull();
    });

    it('throws when Google returns 200 without an access_token', function (): void {
        OauthCredential::query()->create([
            'provider' => 'google',
            'refresh_token' => 'stored-refresh-token',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([], 200),
        ]);

        expect(fn () => (new Ga4TokenProvider)->getAccessToken())
            ->toThrow(GoogleOAuthException::class);
    });
});

describe('Ga4TokenProvider::forget', function (): void {
    it('clears the cached access token so the next call is forced to refresh', function (): void {
        Cache::store('redis')->put('ga4:access_token:google', 'old-token', 300);

        (new Ga4TokenProvider)->forget();

        expect(Cache::store('redis')->get('ga4:access_token:google'))->toBeNull();
    });
});
