<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\PlatformIntegration;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

describe('GET /api/settings/meta — credential write-only response', function (): void {
    it('never returns the raw access token, only a presence flag', function (): void {
        $shop = User::factory()->create();

        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::Meta,
            'active' => true,
            'credentials' => json_encode([
                'pixel_id' => '1234567890',
                'access_token' => 'super-secret-access-token',
                'test_event_code' => 'TEST12345',
            ]),
            'settings' => [],
        ]);

        $response = $this->actingAs($shop)->getJson('/api/settings/meta');

        $response->assertOk();

        $body = $response->getContent();

        expect($body)->not->toContain('super-secret-access-token');

        $response->assertJson([
            'credentials' => [
                'pixel_id' => '1234567890',
                'test_event_code' => 'TEST12345',
                'access_token' => '',
                'has_access_token' => true,
            ],
        ]);
    });
});

describe('POST /api/settings/meta — write-only update semantics', function (): void {
    it('rejects first-time connection attempts missing the access token', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->postJson('/api/settings/meta', [
            'pixel_id' => '1234567890',
            'access_token' => '',
        ]);

        $response->assertStatus(422);
        expect(PlatformIntegration::query()->where('platform', Platform::Meta)->exists())->toBeFalse();
    });

    it('preserves the existing stored access token when resaving with a blank field', function (): void {
        $shop = User::factory()->create();
        Queue::fake();

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['id' => '1234567891', 'name' => 'Test Pixel'], 200),
        ]);

        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::Meta,
            'active' => true,
            'credentials' => json_encode([
                'pixel_id' => '1234567890',
                'access_token' => 'original-access-token',
                'test_event_code' => '',
            ]),
            'settings' => [],
        ]);

        $response = $this->actingAs($shop)->postJson('/api/settings/meta', [
            'pixel_id' => '1234567891',
            'access_token' => '',
        ]);

        $response->assertOk();

        $integration = PlatformIntegration::query()
            ->where('platform', Platform::Meta)
            ->where('user_id', $shop->getKey())
            ->firstOrFail();

        $stored = json_decode($integration->credentials, true);

        expect($stored['pixel_id'])->toBe('1234567891')
            ->and($stored['access_token'])->toBe('original-access-token');
    });

    it('replaces the stored access token when a new value is submitted', function (): void {
        $shop = User::factory()->create();
        Queue::fake();

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['id' => '1234567890', 'name' => 'Test Pixel'], 200),
        ]);

        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::Meta,
            'active' => true,
            'credentials' => json_encode([
                'pixel_id' => '1234567890',
                'access_token' => 'original-access-token',
                'test_event_code' => '',
            ]),
            'settings' => [],
        ]);

        $response = $this->actingAs($shop)->postJson('/api/settings/meta', [
            'pixel_id' => '1234567890',
            'access_token' => 'brand-new-access-token',
        ]);

        $response->assertOk();

        $integration = PlatformIntegration::query()
            ->where('platform', Platform::Meta)
            ->where('user_id', $shop->getKey())
            ->firstOrFail();

        $stored = json_decode($integration->credentials, true);

        expect($stored['access_token'])->toBe('brand-new-access-token');
    });
});
