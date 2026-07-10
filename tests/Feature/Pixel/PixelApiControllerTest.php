<?php

declare(strict_types=1);

use App\Models\User;
use Gnikyt\BasicShopifyAPI\BasicShopifyAPI;

/**
 * Mocks the Shopify Admin GraphQL client on a real User (shop) instance.
 *
 * `Mockery::mock($shop)->makePartial()` returns an "instance mock" — a partial
 * mock that wraps the existing Eloquent instance. Every method that is not
 * explicitly stubbed (attribute access, save(), etc.) is forwarded to the real
 * object, so DB state changes made by the Action under test are observable
 * via fresh queries. Only `api()` is stubbed to avoid any real HTTP call to
 * Shopify's Admin API.
 */
function shopWithFakeApi(array $attributes, BasicShopifyAPI $apiMock): User
{
    $shop = User::factory()->create($attributes);

    $mock = Mockery::mock($shop)->makePartial();
    $mock->shouldReceive('api')->andReturn($apiMock);

    return $mock;
}

describe('PUT /api/pixel', function (): void {
    it('returns 401 or 302 for unauthenticated requests', function (): void {
        $response = $this->putJson('/api/pixel', ['enabled' => true]);

        expect($response->status())->toBeIn([401, 302]);
    });

    it('enables the pixel: calls webPixelCreate, persists state, returns 200', function (): void {
        $apiMock = Mockery::mock(BasicShopifyAPI::class);
        $apiMock->shouldReceive('graph')
            ->once()
            ->withArgs(fn (string $query) => str_contains($query, 'webPixelCreate'))
            ->andReturn([
                'body' => [
                    'data' => [
                        'webPixelCreate' => [
                            'webPixel' => ['id' => 'gid://shopify/WebPixel/123'],
                            'userErrors' => [],
                        ],
                    ],
                ],
            ]);

        $shop = shopWithFakeApi([
            'pixel_enabled' => false,
            'shopify_pixel_id' => null,
            'tracking_secret' => 'super-secret',
        ], $apiMock);

        $response = $this->actingAs($shop)->putJson('/api/pixel', ['enabled' => true]);

        $response->assertOk();
        $response->assertJson([
            'pixel_enabled' => true,
            'shopify_pixel_id' => 'gid://shopify/WebPixel/123',
        ]);

        $fresh = User::query()->find($shop->getKey());
        expect($fresh->pixel_enabled)->toBeTrue();
        expect($fresh->shopify_pixel_id)->toBe('gid://shopify/WebPixel/123');
    });

    it('enables the pixel via webPixelUpdate when a pixel already exists', function (): void {
        $apiMock = Mockery::mock(BasicShopifyAPI::class);
        $apiMock->shouldReceive('graph')
            ->once()
            ->withArgs(fn (string $query) => str_contains($query, 'webPixelUpdate'))
            ->andReturn([
                'body' => [
                    'data' => [
                        'webPixelUpdate' => [
                            'webPixel' => ['id' => 'gid://shopify/WebPixel/123'],
                            'userErrors' => [],
                        ],
                    ],
                ],
            ]);

        $shop = shopWithFakeApi([
            'pixel_enabled' => false,
            'shopify_pixel_id' => 'gid://shopify/WebPixel/123',
            'tracking_secret' => 'super-secret',
        ], $apiMock);

        $response = $this->actingAs($shop)->putJson('/api/pixel', ['enabled' => true]);

        $response->assertOk();
        $response->assertJson([
            'pixel_enabled' => true,
            'shopify_pixel_id' => 'gid://shopify/WebPixel/123',
        ]);

        $fresh = User::query()->find($shop->getKey());
        expect($fresh->pixel_enabled)->toBeTrue();
    });

    it('disables the pixel: calls webPixelDelete, clears state, returns 200', function (): void {
        $apiMock = Mockery::mock(BasicShopifyAPI::class);
        $apiMock->shouldReceive('graph')
            ->once()
            ->withArgs(fn (string $query) => str_contains($query, 'webPixelDelete'))
            ->andReturn([
                'body' => [
                    'data' => [
                        'webPixelDelete' => [
                            'deletedWebPixelId' => 'gid://shopify/WebPixel/123',
                            'userErrors' => [],
                        ],
                    ],
                ],
            ]);

        $shop = shopWithFakeApi([
            'pixel_enabled' => true,
            'shopify_pixel_id' => 'gid://shopify/WebPixel/123',
            'tracking_secret' => 'super-secret',
        ], $apiMock);

        $response = $this->actingAs($shop)->putJson('/api/pixel', ['enabled' => false]);

        $response->assertOk();
        $response->assertJson([
            'pixel_enabled' => false,
            'shopify_pixel_id' => null,
        ]);

        $fresh = User::query()->find($shop->getKey());
        expect($fresh->pixel_enabled)->toBeFalse();
        expect($fresh->shopify_pixel_id)->toBeNull();
    });

    it('returns 422 and leaves state unchanged when Shopify returns userErrors on enable', function (): void {
        $apiMock = Mockery::mock(BasicShopifyAPI::class);
        $apiMock->shouldReceive('graph')
            ->once()
            ->andReturn([
                'body' => [
                    'data' => [
                        'webPixelCreate' => [
                            'webPixel' => null,
                            'userErrors' => [
                                ['field' => ['settings'], 'message' => 'Something went wrong.'],
                            ],
                        ],
                    ],
                ],
            ]);

        $shop = shopWithFakeApi([
            'pixel_enabled' => false,
            'shopify_pixel_id' => null,
            'tracking_secret' => 'super-secret',
        ], $apiMock);

        $response = $this->actingAs($shop)->putJson('/api/pixel', ['enabled' => true]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message']);

        $fresh = User::query()->find($shop->getKey());
        expect($fresh->pixel_enabled)->toBeFalse();
        expect($fresh->shopify_pixel_id)->toBeNull();
    });

    it('returns 422 and leaves state unchanged when Shopify returns userErrors on disable', function (): void {
        $apiMock = Mockery::mock(BasicShopifyAPI::class);
        $apiMock->shouldReceive('graph')
            ->once()
            ->andReturn([
                'body' => [
                    'data' => [
                        'webPixelDelete' => [
                            'deletedWebPixelId' => null,
                            'userErrors' => [
                                ['field' => ['id'], 'message' => 'Could not delete.'],
                            ],
                        ],
                    ],
                ],
            ]);

        $shop = shopWithFakeApi([
            'pixel_enabled' => true,
            'shopify_pixel_id' => 'gid://shopify/WebPixel/123',
            'tracking_secret' => 'super-secret',
        ], $apiMock);

        $response = $this->actingAs($shop)->putJson('/api/pixel', ['enabled' => false]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message']);

        $fresh = User::query()->find($shop->getKey());
        expect($fresh->pixel_enabled)->toBeTrue();
        expect($fresh->shopify_pixel_id)->toBe('gid://shopify/WebPixel/123');
    });

    it('returns 422 when the enabled field is missing', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->putJson('/api/pixel', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['enabled']);
    });

    it('returns 422 when the enabled field is not a boolean', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->putJson('/api/pixel', ['enabled' => 'not-a-boolean']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['enabled']);
    });
});
