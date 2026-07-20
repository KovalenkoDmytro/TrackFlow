<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\ConversionActionMapping;
use App\Models\OauthCredential;
use App\Models\PlatformIntegration;
use App\Models\User;
use App\Services\Ga4TokenProvider;
use App\Services\GoogleAnalytics4Client;
use Illuminate\Support\Facades\Http;

/**
 * Regression coverage: GoogleAnalytics4Client used to read a per-shop
 * $credentials['oauth'] value. It now depends entirely on the injected,
 * shared Ga4TokenProvider and must never look at $credentials['oauth'] again
 * — these tests deliberately plant a stray "oauth" key with a value that
 * would break the old code path, to prove it is never touched.
 *
 * Ga4TokenProvider is `final`, so it cannot be mocked with Mockery — the real
 * instance is used here, backed by a real OauthCredential row and Http::fake().
 */
beforeEach(function (): void {
    config(['cache.stores.redis' => ['driver' => 'array']]);
});

describe('GoogleAnalytics4Client::testCredentials', function (): void {
    it('never resolves an access token when no property_id is supplied, ignoring a stray "oauth" credentials key', function (): void {
        Http::fake([
            'google-analytics.com/debug/mp/collect*' => Http::response(['validationMessages' => []], 200),
        ]);

        $client = new GoogleAnalytics4Client(new Ga4TokenProvider);

        $client->testCredentials([
            'measurement_id' => 'G-TEST',
            'api_secret' => 'secret',
            'oauth' => ['refresh_token' => 'legacy-per-shop-token-should-be-ignored'],
        ]);

        Http::assertNotSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token');
    });

    it('resolves the access token from the injected Ga4TokenProvider (not $credentials[\'oauth\']) when property_id is present', function (): void {
        OauthCredential::query()->create(['provider' => 'google', 'refresh_token' => 'shared-refresh-token']);

        Http::fake([
            'google-analytics.com/debug/mp/collect*' => Http::response(['validationMessages' => []], 200),
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'shared-operator-token', 'expires_in' => 3600], 200),
            'analyticsadmin.googleapis.com/*' => Http::response(['keyEvents' => []], 200),
        ]);

        $client = new GoogleAnalytics4Client(new Ga4TokenProvider);

        $client->testCredentials([
            'measurement_id' => 'G-TEST',
            'api_secret' => 'secret',
            'property_id' => '999',
            'oauth' => ['refresh_token' => 'legacy-per-shop-token-should-be-ignored'],
        ]);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer shared-operator-token'));
    });
});

describe('GoogleAnalytics4Client::setupConversionActions', function (): void {
    it('resolves the access token from the injected Ga4TokenProvider when property_id is present, ignoring a stray "oauth" key', function (): void {
        OauthCredential::query()->create(['provider' => 'google', 'refresh_token' => 'shared-refresh-token']);

        $shop = User::factory()->create();
        $integration = PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAnalytics4,
            'active' => true,
            'credentials' => json_encode([
                'measurement_id' => 'G-TEST',
                'api_secret' => 'secret',
                'property_id' => '999',
                'oauth' => ['refresh_token' => 'legacy-per-shop-token-should-be-ignored'],
            ]),
            'settings' => [],
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'shared-operator-token', 'expires_in' => 3600], 200),
            'analyticsadmin.googleapis.com/*' => Http::response(['keyEvents' => []], 200),
        ]);

        $client = new GoogleAnalytics4Client(new Ga4TokenProvider);
        $client->setupConversionActions($integration);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer shared-operator-token'));
        expect(ConversionActionMapping::query()->where('platform_integration_id', $integration->getKey())->count())->toBe(9);
    });

    it('never resolves an access token when property_id is absent from credentials', function (): void {
        $shop = User::factory()->create();
        $integration = PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAnalytics4,
            'active' => true,
            'credentials' => json_encode(['measurement_id' => 'G-TEST', 'api_secret' => 'secret']),
            'settings' => [],
        ]);

        Http::fake();

        // No OauthCredential row exists at all — if the code ever tried to
        // resolve an access token here it would throw, since there is
        // nothing to refresh against.
        $client = new GoogleAnalytics4Client(new Ga4TokenProvider);
        $client->setupConversionActions($integration);

        Http::assertNotSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token');
        expect(ConversionActionMapping::query()->where('platform_integration_id', $integration->getKey())->count())->toBe(9);
    });
});
