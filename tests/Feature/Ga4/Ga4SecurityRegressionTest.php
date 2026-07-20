<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\OauthCredential;
use App\Models\PlatformIntegration;
use App\Models\ShopGa4Setting;
use App\Models\User;

/**
 * The shared Google refresh_token (and the app's client_secret) must never
 * appear in any JSON response body — one leaked value would compromise GA4
 * reporting for every shop, not just one.
 */
beforeEach(function (): void {
    config(['cache.stores.redis' => ['driver' => 'array']]);
});

describe('GA4 endpoints never leak refresh_token or client_secret', function (): void {
    it('Ga4ApiController@show does not expose refresh_token', function (): void {
        $shop = User::factory()->create();
        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAnalytics4,
            'active' => true,
            'credentials' => json_encode(['measurement_id' => 'G-1', 'api_secret' => 'secret']),
            'settings' => [],
        ]);

        $response = $this->actingAs($shop)->getJson('/api/settings/ga4');

        $response->assertOk();
        expect($response->getContent())->not->toContain('refresh_token');
    });

    it('ShopGa4SettingApiController does not expose refresh_token', function (): void {
        $shop = User::factory()->create();
        ShopGa4Setting::query()->create(['user_id' => $shop->getKey(), 'property_id' => '1', 'active' => true]);

        $response = $this->actingAs($shop)->getJson('/api/settings/ga4-property');

        $response->assertOk();
        expect($response->getContent())->not->toContain('refresh_token');
    });

    it('GoogleOperatorStatusApiController exposes connection status but never the raw refresh_token', function (): void {
        $shop = User::factory()->create();
        OauthCredential::query()->create(['provider' => 'google', 'refresh_token' => 'super-secret-refresh-token']);

        $response = $this->actingAs($shop)->getJson('/api/operator/google-status');

        $response->assertOk();
        expect($response->getContent())->not->toContain('super-secret-refresh-token');
        expect($response->getContent())->not->toContain('refresh_token');
    });

    it('Ga4ReportingApiController error responses never leak the refresh_token', function (): void {
        $shop = User::factory()->create();
        ShopGa4Setting::query()->create(['user_id' => $shop->getKey(), 'property_id' => '1', 'active' => true]);

        // No OauthCredential connected -> Ga4TokenProvider throws GoogleOAuthException -> 424.
        $response = $this->actingAs($shop)->getJson('/api/ga4/report?start_date=2026-07-01&end_date=2026-07-07');

        $response->assertStatus(424);
        expect($response->getContent())->not->toContain('refresh_token');
    });

    it('none of the GA4 JSON responses ever include the app\'s Google client_secret', function (): void {
        config(['services.google.client_secret' => 'app-level-client-secret-value']);

        $shop = User::factory()->create();
        OauthCredential::query()->create(['provider' => 'google', 'refresh_token' => 'irrelevant']);
        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAnalytics4,
            'active' => true,
            'credentials' => json_encode(['measurement_id' => 'G-1', 'api_secret' => 'secret']),
            'settings' => [],
        ]);
        ShopGa4Setting::query()->create(['user_id' => $shop->getKey(), 'property_id' => '1', 'active' => true]);

        $responses = [
            $this->actingAs($shop)->getJson('/api/settings/ga4'),
            $this->actingAs($shop)->getJson('/api/settings/ga4-property'),
            $this->actingAs($shop)->getJson('/api/operator/google-status'),
        ];

        foreach ($responses as $response) {
            expect($response->getContent())->not->toContain('app-level-client-secret-value');
        }
    });
});
