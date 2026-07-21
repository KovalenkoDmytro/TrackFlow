<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\PlatformIntegration;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

describe('GET /api/settings/google-ads — credential write-only response', function (): void {
    it('never returns the raw developer token or oauth secrets, only presence flags', function (): void {
        $shop = User::factory()->create();

        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAds,
            'active' => true,
            'credentials' => json_encode([
                'customer_id' => '1234567890',
                'mcc_id' => null,
                'developer_token' => 'super-secret-developer-token',
                'oauth' => [
                    'client_id' => 'client-id-value.apps.googleusercontent.com',
                    'client_secret' => 'GOCSPX-super-secret-client-secret',
                    'refresh_token' => '1//super-secret-refresh-token',
                ],
            ]),
            'settings' => [],
        ]);

        $response = $this->actingAs($shop)->getJson('/api/settings/google-ads');

        $response->assertOk();

        $body = $response->getContent();

        expect($body)
            ->not->toContain('super-secret-developer-token')
            ->not->toContain('GOCSPX-super-secret-client-secret')
            ->not->toContain('1//super-secret-refresh-token')
            ->not->toContain('client-id-value.apps.googleusercontent.com');

        $response->assertJson([
            'credentials' => [
                'customer_id' => '1234567890',
                'developer_token' => '',
                'oauth' => [
                    'client_id' => '',
                    'client_secret' => '',
                    'refresh_token' => '',
                ],
                'has_developer_token' => true,
                'has_oauth_client_id' => true,
                'has_oauth_client_secret' => true,
                'has_oauth_refresh_token' => true,
            ],
        ]);
    });
});

describe('POST /api/settings/google-ads — write-only update semantics', function (): void {
    it('rejects first-time connection attempts missing required sensitive fields', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->postJson('/api/settings/google-ads', [
            'customer_id' => '123-456-7890',
            'developer_token' => '',
            'oauth_client_id' => '',
            'oauth_client_secret' => '',
            'oauth_refresh_token' => '',
        ]);

        $response->assertStatus(422);
        expect(PlatformIntegration::query()->where('platform', Platform::GoogleAds)->exists())->toBeFalse();
    });

    it('preserves the existing stored developer token and oauth secrets when resaving with blank fields', function (): void {
        $shop = User::factory()->create();
        Queue::fake();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token'], 200),
            'https://googleads.googleapis.com/*' => Http::response(['results' => []], 200),
        ]);

        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAds,
            'active' => true,
            'credentials' => json_encode([
                'customer_id' => '1234567890',
                'mcc_id' => null,
                'developer_token' => 'original-developer-token',
                'oauth' => [
                    'client_id' => 'original-client-id',
                    'client_secret' => 'original-client-secret',
                    'refresh_token' => 'original-refresh-token',
                ],
            ]),
            'settings' => [],
        ]);

        $response = $this->actingAs($shop)->postJson('/api/settings/google-ads', [
            'customer_id' => '123-456-7891',
            'developer_token' => '',
            'oauth_client_id' => '',
            'oauth_client_secret' => '',
            'oauth_refresh_token' => '',
        ]);

        $response->assertOk();

        $integration = PlatformIntegration::query()
            ->where('platform', Platform::GoogleAds)
            ->where('user_id', $shop->getKey())
            ->firstOrFail();

        $stored = json_decode($integration->credentials, true);

        expect($stored['customer_id'])->toBe('1234567891')
            ->and($stored['developer_token'])->toBe('original-developer-token')
            ->and($stored['oauth']['client_id'])->toBe('original-client-id')
            ->and($stored['oauth']['client_secret'])->toBe('original-client-secret')
            ->and($stored['oauth']['refresh_token'])->toBe('original-refresh-token');
    });

    it('replaces the stored developer token when a new value is submitted', function (): void {
        $shop = User::factory()->create();
        Queue::fake();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token'], 200),
            'https://googleads.googleapis.com/*' => Http::response(['results' => []], 200),
        ]);

        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAds,
            'active' => true,
            'credentials' => json_encode([
                'customer_id' => '1234567890',
                'mcc_id' => null,
                'developer_token' => 'original-developer-token',
                'oauth' => [
                    'client_id' => 'original-client-id',
                    'client_secret' => 'original-client-secret',
                    'refresh_token' => 'original-refresh-token',
                ],
            ]),
            'settings' => [],
        ]);

        $response = $this->actingAs($shop)->postJson('/api/settings/google-ads', [
            'customer_id' => '123-456-7890',
            'developer_token' => 'brand-new-developer-token',
            'oauth_client_id' => '',
            'oauth_client_secret' => '',
            'oauth_refresh_token' => '',
        ]);

        $response->assertOk();

        $integration = PlatformIntegration::query()
            ->where('platform', Platform::GoogleAds)
            ->where('user_id', $shop->getKey())
            ->firstOrFail();

        $stored = json_decode($integration->credentials, true);

        expect($stored['developer_token'])->toBe('brand-new-developer-token');
    });
});
