<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\PlatformIntegration;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

describe('GET /api/settings/ga4 — credential write-only response', function (): void {
    it('never returns the raw oauth secrets, only presence flags', function (): void {
        $shop = User::factory()->create();

        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAnalytics4,
            'active' => true,
            'credentials' => json_encode([
                'measurement_id' => 'G-ABC123',
                'api_secret' => 'super-secret-api-key',
                'property_id' => '123456789',
                'oauth' => [
                    'client_id' => 'client-id-value.apps.googleusercontent.com',
                    'client_secret' => 'GOCSPX-super-secret-client-secret',
                    'refresh_token' => '1//super-secret-refresh-token',
                ],
            ]),
            'settings' => [],
        ]);

        $response = $this->actingAs($shop)->getJson('/api/settings/ga4');

        $response->assertOk();

        $body = $response->getContent();

        expect($body)
            ->not->toContain('GOCSPX-super-secret-client-secret')
            ->not->toContain('1//super-secret-refresh-token')
            ->not->toContain('client-id-value.apps.googleusercontent.com');

        $response->assertJson([
            'connected' => true,
            'credentials' => [
                'measurement_id' => 'G-ABC123',
                'property_id' => '123456789',
                'oauth_client_id' => '',
                'oauth_client_secret' => '',
                'oauth_refresh_token' => '',
                'has_oauth_client_id' => true,
                'has_oauth_client_secret' => true,
                'has_oauth_refresh_token' => true,
            ],
        ]);
    });

    it('reports has_* flags as false when no oauth credentials are stored', function (): void {
        $shop = User::factory()->create();

        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAnalytics4,
            'active' => true,
            'credentials' => json_encode([
                'measurement_id' => 'G-ABC123',
                'api_secret' => 'super-secret-api-key',
            ]),
            'settings' => [],
        ]);

        $response = $this->actingAs($shop)->getJson('/api/settings/ga4');

        $response->assertOk();
        $response->assertJson([
            'credentials' => [
                'has_oauth_client_id' => false,
                'has_oauth_client_secret' => false,
                'has_oauth_refresh_token' => false,
            ],
        ]);
    });
});

describe('POST /api/settings/ga4 — write-only update semantics', function (): void {
    it('requires all oauth fields when connecting with a property_id for the first time', function (): void {
        $shop = User::factory()->create();

        Http::fake([
            'https://www.google-analytics.com/debug/mp/collect*' => Http::response(['validationMessages' => []], 200),
        ]);

        $response = $this->actingAs($shop)->postJson('/api/settings/ga4', [
            'measurement_id' => 'G-ABC123',
            'api_secret' => 'api-secret-value',
            'property_id' => '123456789',
            'oauth_client_id' => '',
            'oauth_client_secret' => '',
            'oauth_refresh_token' => '',
        ]);

        $response->assertStatus(422);
        expect(PlatformIntegration::query()->where('platform', Platform::GoogleAnalytics4)->exists())->toBeFalse();
    });

    it('preserves the existing stored oauth secrets when resaving with blank fields', function (): void {
        $shop = User::factory()->create();
        Queue::fake();

        Http::fake([
            'https://www.google-analytics.com/debug/mp/collect*' => Http::response(['validationMessages' => []], 200),
            'https://analyticsadmin.googleapis.com/*' => Http::response(['keyEvents' => []], 200),
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token'], 200),
        ]);

        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAnalytics4,
            'active' => true,
            'credentials' => json_encode([
                'measurement_id' => 'G-ABC123',
                'api_secret' => 'original-api-secret',
                'property_id' => '123456789',
                'oauth' => [
                    'client_id' => 'original-client-id',
                    'client_secret' => 'original-client-secret',
                    'refresh_token' => 'original-refresh-token',
                ],
            ]),
            'settings' => [],
        ]);

        $response = $this->actingAs($shop)->postJson('/api/settings/ga4', [
            'measurement_id' => 'G-ABC123',
            'api_secret' => 'new-api-secret',
            'property_id' => '123456789',
            'oauth_client_id' => '',
            'oauth_client_secret' => '',
            'oauth_refresh_token' => '',
        ]);

        $response->assertOk();

        $integration = PlatformIntegration::query()
            ->where('platform', Platform::GoogleAnalytics4)
            ->where('user_id', $shop->getKey())
            ->firstOrFail();

        $stored = json_decode($integration->credentials, true);

        expect($stored['api_secret'])->toBe('new-api-secret')
            ->and($stored['oauth']['client_id'])->toBe('original-client-id')
            ->and($stored['oauth']['client_secret'])->toBe('original-client-secret')
            ->and($stored['oauth']['refresh_token'])->toBe('original-refresh-token');
    });

    it('replaces the stored oauth secret when a new value is submitted', function (): void {
        $shop = User::factory()->create();
        Queue::fake();

        Http::fake([
            'https://www.google-analytics.com/debug/mp/collect*' => Http::response(['validationMessages' => []], 200),
            'https://analyticsadmin.googleapis.com/*' => Http::response(['keyEvents' => []], 200),
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token'], 200),
        ]);

        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAnalytics4,
            'active' => true,
            'credentials' => json_encode([
                'measurement_id' => 'G-ABC123',
                'api_secret' => 'original-api-secret',
                'property_id' => '123456789',
                'oauth' => [
                    'client_id' => 'original-client-id',
                    'client_secret' => 'original-client-secret',
                    'refresh_token' => 'original-refresh-token',
                ],
            ]),
            'settings' => [],
        ]);

        $response = $this->actingAs($shop)->postJson('/api/settings/ga4', [
            'measurement_id' => 'G-ABC123',
            'api_secret' => 'new-api-secret',
            'property_id' => '123456789',
            'oauth_client_id' => 'original-client-id',
            'oauth_client_secret' => 'brand-new-client-secret',
            'oauth_refresh_token' => 'original-refresh-token',
        ]);

        $response->assertOk();

        $integration = PlatformIntegration::query()
            ->where('platform', Platform::GoogleAnalytics4)
            ->where('user_id', $shop->getKey())
            ->firstOrFail();

        $stored = json_decode($integration->credentials, true);

        expect($stored['oauth']['client_secret'])->toBe('brand-new-client-secret');
    });
});
