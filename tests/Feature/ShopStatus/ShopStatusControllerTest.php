<?php

declare(strict_types=1);

use App\Models\User;

describe('GET /api/shop-status', function (): void {
    it('returns 401 for unauthenticated requests', function (): void {
        $response = $this->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');
    });

    it('returns pixel_enabled=true when the shop has enabled the pixel', function (): void {
        $shop = User::factory()->create([
            'pixel_enabled' => true,
            'shopify_pixel_id' => 'gid://shopify/WebPixel/123',
        ]);

        $response = $this->withToken($this->shopifySessionToken($shop))->getJson('/api/shop-status');

        $response->assertOk();
        $response->assertJson([
            'shop' => [
                'name' => $shop->name,
                'shopify_pixel_id' => 'gid://shopify/WebPixel/123',
                'pixel_enabled' => true,
            ],
        ]);
    });

    it('returns pixel_enabled=false when the shop has never enabled the pixel', function (): void {
        $shop = User::factory()->create([
            'pixel_enabled' => false,
            'shopify_pixel_id' => null,
        ]);

        $response = $this->withToken($this->shopifySessionToken($shop))->getJson('/api/shop-status');

        $response->assertOk();
        $response->assertJson([
            'shop' => [
                'name' => $shop->name,
                'shopify_pixel_id' => null,
                'pixel_enabled' => false,
            ],
        ]);
    });
});
