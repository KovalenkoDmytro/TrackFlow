<?php

declare(strict_types=1);

use App\Contracts\PlatformResolverContract;
use App\Enums\Platform;
use App\Models\PlatformIntegration;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

describe('POST /api/settings/meta — synchronous conversion action setup', function (): void {
    beforeEach(function (): void {
        Http::fake(['https://graph.facebook.com/*' => Http::response(['id' => '1234567890'], 200)]);
    });

    it('creates the event mappings before responding, without queueing a job', function (): void {
        Queue::fake();
        $shop = User::factory()->create();

        $response = $this->withToken($this->shopifySessionToken($shop))->postJson('/api/settings/meta', [
            'pixel_id' => '1234567890',
            'access_token' => 'token',
        ]);

        $response->assertOk()->assertJson(['success' => true, 'integration' => ['active' => true]]);
        Queue::assertNothingPushed();

        $integration = PlatformIntegration::query()->where('platform', Platform::Meta)->firstOrFail();
        expect($integration->conversionActionMappings()->where('active', true)->count())->toBeGreaterThan(0);
    });

    it('returns an error and persists nothing when setup fails', function (): void {
        $shop = User::factory()->create();
        $this->mock(PlatformResolverContract::class)
            ->shouldReceive('resolve')
            ->andThrow(new InvalidArgumentException('No driver'));

        $response = $this->withToken($this->shopifySessionToken($shop))->postJson('/api/settings/meta', [
            'pixel_id' => '1234567890',
            'access_token' => 'token',
        ]);

        $response->assertStatus(500)->assertJsonStructure(['error']);
        expect(PlatformIntegration::query()->where('platform', Platform::Meta)->exists())->toBeFalse();
    });
});
