<?php

declare(strict_types=1);

use App\Actions\GoogleAds\CreateConversionActions;
use App\Enums\Platform;
use App\Models\PlatformIntegration;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

describe('GET /api/settings/ga4', function (): void {
    it('returns 401 or 302 for unauthenticated requests', function (): void {
        $response = $this->getJson('/api/settings/ga4');

        expect($response->status())->toBeIn([401, 302]);
    });

    it('returns disconnected defaults with no oauth key at all when no integration exists', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->getJson('/api/settings/ga4');

        $response->assertOk();
        $response->assertExactJson([
            'connected' => false,
            'credentials' => [
                'measurement_id' => '',
                'api_secret' => '',
                'property_id' => '',
            ],
        ]);
    });

    it('returns the shop\'s own credentials without ever including an oauth key', function (): void {
        $shop = User::factory()->create();
        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAnalytics4,
            'active' => true,
            'credentials' => json_encode(['measurement_id' => 'G-123', 'api_secret' => 'secret-abc', 'property_id' => '999']),
            'settings' => [],
        ]);

        $response = $this->actingAs($shop)->getJson('/api/settings/ga4');

        $response->assertOk();
        $response->assertExactJson([
            'connected' => true,
            'credentials' => [
                'measurement_id' => 'G-123',
                'api_secret' => 'secret-abc',
                'property_id' => '999',
            ],
        ]);
    });
});

describe('POST /api/settings/ga4', function (): void {
    // GoogleAnalytics4Client is `final`, so it cannot be mocked with Mockery
    // — Http::fake() on the Measurement Protocol debug endpoint it calls
    // internally is used as the seam instead.
    it('validates credentials via GoogleAnalytics4Client before saving, without ever needing per-shop oauth', function (): void {
        Queue::fake();
        $shop = User::factory()->create();

        Http::fake([
            'google-analytics.com/debug/mp/collect*' => Http::response(['validationMessages' => []], 200),
        ]);

        $response = $this->actingAs($shop)->postJson('/api/settings/ga4', [
            'measurement_id' => 'G-123',
            'api_secret' => 'secret-abc',
        ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Connected successfully']);

        $integration = PlatformIntegration::query()->where('user_id', $shop->getKey())->first();
        expect($integration)->not->toBeNull();
        expect($integration->active)->toBeTrue();

        CreateConversionActions::assertPushed();

        // No property_id was supplied, so no attempt should ever be made to
        // resolve the shared operator OAuth token.
        Http::assertNotSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token');
    });

    it('returns 422 with the validation message when testCredentials fails, and never persists the integration', function (): void {
        $shop = User::factory()->create();

        Http::fake([
            'google-analytics.com/debug/mp/collect*' => Http::response([
                'validationMessages' => [
                    ['severity' => 'ERROR', 'description' => 'api_secret is invalid.'],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($shop)->postJson('/api/settings/ga4', [
            'measurement_id' => 'G-123',
            'api_secret' => 'bad-secret',
        ]);

        $response->assertStatus(422);
        expect($response->json('message'))->toContain('api_secret is invalid.');
        expect(PlatformIntegration::query()->where('user_id', $shop->getKey())->exists())->toBeFalse();
    });
});
