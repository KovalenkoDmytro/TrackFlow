<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\ConversionActionMapping;
use App\Models\PlatformIntegration;
use App\Models\User;

function metaIntegrationFor(User $shop, bool $active): PlatformIntegration
{
    return PlatformIntegration::query()->create([
        'user_id' => $shop->getKey(),
        'platform' => Platform::Meta,
        'active' => $active,
        'credentials' => null,
        'settings' => [],
    ]);
}

describe('GET /api/analytics?platform=meta — integration state', function (): void {
    it('reports no integration when Meta was never connected', function (): void {
        $shop = User::factory()->create();

        $response = $this->withToken($this->shopifySessionToken($shop))->getJson('/api/analytics?platform=meta');

        expect($response->json('summary.integration'))->toBe(['active' => false, 'active_mappings' => 0]);
    });

    it('reports an inactive integration', function (): void {
        $shop = User::factory()->create();
        metaIntegrationFor($shop, false);

        $response = $this->withToken($this->shopifySessionToken($shop))->getJson('/api/analytics?platform=meta');

        expect($response->json('summary.integration.active'))->toBeFalse();
    });

    it('reports an active integration with no mappings', function (): void {
        $shop = User::factory()->create();
        metaIntegrationFor($shop, true);

        $response = $this->withToken($this->shopifySessionToken($shop))->getJson('/api/analytics?platform=meta');

        expect($response->json('summary.integration'))->toBe(['active' => true, 'active_mappings' => 0]);
    });

    it('counts only active mappings', function (): void {
        $shop = User::factory()->create();
        $integration = metaIntegrationFor($shop, true);
        foreach ([['purchase', true], ['add_to_cart', true], ['view_item', false]] as [$event, $active]) {
            ConversionActionMapping::query()->create([
                'platform_integration_id' => $integration->getKey(),
                'event' => $event,
                'external_action_id' => $event,
                'active' => $active,
            ]);
        }

        $response = $this->withToken($this->shopifySessionToken($shop))->getJson('/api/analytics?platform=meta');

        expect($response->json('summary.integration'))->toBe(['active' => true, 'active_mappings' => 2]);
    });
});
