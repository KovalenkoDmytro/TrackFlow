<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\PlatformIntegration;
use App\Models\User;
use Illuminate\Support\Facades\Http;

describe('GET /api/settings/ga4', function (): void {
    it('returns 401 or 302 for unauthenticated requests', function (): void {
        $response = $this->getJson('/api/settings/ga4');

        expect($response->status())->toBeIn([401, 302]);
    });

    it('returns empty credentials and has_oauth_connection false when no integration exists', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->getJson('/api/settings/ga4');

        $response->assertOk()->assertJson([
            'connected' => false,
            'credentials' => [
                'measurement_id' => '',
                'api_secret' => '',
                'property_id' => '',
            ],
            'has_oauth_connection' => false,
        ]);
    });

    it('never exposes the raw oauth_refresh_token, only has_oauth_connection', function (): void {
        $shop = User::factory()->create();

        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAnalytics4,
            'active' => true,
            'credentials' => json_encode([
                'measurement_id' => 'G-TEST123',
                'api_secret' => 'secret',
                'property_id' => '123456',
                'oauth_refresh_token' => 'super-secret-refresh-token',
            ]),
            'settings' => [],
        ]);

        $response = $this->actingAs($shop)->getJson('/api/settings/ga4');

        $response->assertOk();
        $response->assertJson([
            'connected' => true,
            'credentials' => [
                'measurement_id' => 'G-TEST123',
                'api_secret' => 'secret',
                'property_id' => '123456',
            ],
            'has_oauth_connection' => true,
        ]);
        expect($response->json())->not->toContain('super-secret-refresh-token');
        expect(json_encode($response->json()))->not->toContain('oauth_refresh_token');
    });
});

describe('POST /api/settings/ga4', function (): void {
    it('rejects requests without measurement_id or api_secret', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->postJson('/api/settings/ga4', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['measurement_id', 'api_secret']);
    });

    it('no longer accepts oauth_client_id or oauth_client_secret fields', function (): void {
        $shop = User::factory()->create();

        Http::fake([
            'google-analytics.com/debug/mp/collect*' => Http::response(['validationMessages' => []], 200),
        ]);

        $response = $this->actingAs($shop)->postJson('/api/settings/ga4', [
            'measurement_id' => 'G-TEST123',
            'api_secret' => 'secret',
            'oauth_client_id' => 'should-be-ignored',
            'oauth_client_secret' => 'should-be-ignored',
        ]);

        $response->assertOk();

        $integration = PlatformIntegration::query()
            ->where('user_id', $shop->getKey())
            ->where('platform', Platform::GoogleAnalytics4)
            ->first();

        $stored = json_decode((string) $integration->credentials, true);
        expect($stored)->not->toHaveKey('oauth_client_id');
        expect($stored)->not->toHaveKey('oauth_client_secret');
        expect($stored)->not->toHaveKey('oauth');
    });

    it('preserves an existing oauth_refresh_token when the form is resaved', function (): void {
        $shop = User::factory()->create();

        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAnalytics4,
            'active' => true,
            'credentials' => json_encode([
                'measurement_id' => 'G-OLD',
                'api_secret' => 'old-secret',
                'oauth_refresh_token' => 'existing-refresh-token',
            ]),
            'settings' => [],
        ]);

        Http::fake([
            'google-analytics.com/debug/mp/collect*' => Http::response(['validationMessages' => []], 200),
        ]);

        $response = $this->actingAs($shop)->postJson('/api/settings/ga4', [
            'measurement_id' => 'G-NEW',
            'api_secret' => 'new-secret',
        ]);

        $response->assertOk();

        $integration = PlatformIntegration::query()
            ->where('user_id', $shop->getKey())
            ->where('platform', Platform::GoogleAnalytics4)
            ->first();

        $stored = json_decode((string) $integration->credentials, true);
        expect($stored['measurement_id'])->toBe('G-NEW');
        expect($stored['oauth_refresh_token'])->toBe('existing-refresh-token');
    });

    it('does not accept a client-submitted oauth_refresh_token value', function (): void {
        $shop = User::factory()->create();

        Http::fake([
            'google-analytics.com/debug/mp/collect*' => Http::response(['validationMessages' => []], 200),
        ]);

        $response = $this->actingAs($shop)->postJson('/api/settings/ga4', [
            'measurement_id' => 'G-TEST123',
            'api_secret' => 'secret',
            'oauth_refresh_token' => 'attacker-supplied-token',
        ]);

        $response->assertOk();

        $integration = PlatformIntegration::query()
            ->where('user_id', $shop->getKey())
            ->where('platform', Platform::GoogleAnalytics4)
            ->first();

        $stored = json_decode((string) $integration->credentials, true);
        expect($stored)->not->toHaveKey('oauth_refresh_token');
    });
});
