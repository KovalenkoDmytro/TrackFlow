<?php

declare(strict_types=1);

use App\Models\User;
use Gnikyt\BasicShopifyAPI\BasicShopifyAPI;
use Gnikyt\BasicShopifyAPI\ResponseAccess;

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

    it('returns 422 with the real Shopify error when the GraphQL response has top-level errors on enable', function (): void {
        $apiMock = Mockery::mock(BasicShopifyAPI::class);
        $apiMock->shouldReceive('graph')
            ->once()
            ->withArgs(fn (string $query) => str_contains($query, 'webPixelCreate'))
            ->andReturn([
                'errors' => [
                    ['message' => 'Access denied for webPixelCreate field. Required access: write_pixels.'],
                ],
                'body' => [
                    'data' => null,
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
        expect($response->json('message'))->toContain('Access denied for webPixelCreate field. Required access: write_pixels.');

        $fresh = User::query()->find($shop->getKey());
        expect($fresh->pixel_enabled)->toBeFalse();
        expect($fresh->shopify_pixel_id)->toBeNull();
    });

    it('returns 422 with the real Shopify error when the GraphQL response has top-level errors on disable', function (): void {
        $apiMock = Mockery::mock(BasicShopifyAPI::class);
        $apiMock->shouldReceive('graph')
            ->once()
            ->withArgs(fn (string $query) => str_contains($query, 'webPixelDelete'))
            ->andReturn([
                'errors' => [
                    ['message' => 'Throttled'],
                ],
                'body' => [
                    'data' => null,
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
        expect($response->json('message'))->toContain('Throttled');

        $fresh = User::query()->find($shop->getKey());
        expect($fresh->pixel_enabled)->toBeTrue();
        expect($fresh->shopify_pixel_id)->toBe('gid://shopify/WebPixel/123');
    });

    it('returns 422 with a readable message (not "true") when the HTTP request itself fails (e.g. throttling/5xx)', function (): void {
        // Mirrors gnikyt/basic-shopify-api's Graph::handleFailure() shape for a
        // non-2xx HTTP response (429 throttling, 5xx, etc.): `errors` is the
        // literal boolean `true`, not an array of {message, ...}, and any
        // decoded error details (if present) live directly in `body`.
        $apiMock = Mockery::mock(BasicShopifyAPI::class);
        $apiMock->shouldReceive('graph')
            ->once()
            ->withArgs(fn (string $query) => str_contains($query, 'webPixelCreate'))
            ->andReturn([
                'errors' => true,
                'response' => null,
                'status' => 429,
                'body' => null,
                'exception' => null,
                'timestamps' => [],
            ]);

        $shop = shopWithFakeApi([
            'pixel_enabled' => false,
            'shopify_pixel_id' => null,
            'tracking_secret' => 'super-secret',
        ], $apiMock);

        $response = $this->actingAs($shop)->putJson('/api/pixel', ['enabled' => true]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message']);
        expect($response->json('message'))->not->toContain('true');
        expect($response->json('message'))->toContain('rate limiting');

        $fresh = User::query()->find($shop->getKey());
        expect($fresh->pixel_enabled)->toBeFalse();
        expect($fresh->shopify_pixel_id)->toBeNull();
    });

    /**
     * Regression tests for a bug where `userErrors` empty-array checks used
     * `empty()`/`!empty()` on the response. In production, the `body` key of
     * the Shopify GraphQL response is a `Gnikyt\BasicShopifyAPI\ResponseAccess`
     * object rather than a plain PHP array. Because `ResponseAccess::offsetGet`
     * wraps any array value (including an empty one) in a new `ResponseAccess`
     * instance, `userErrors` is *always* an object in real responses — even
     * when it's empty. `empty($object)` is always `false` for a non-null
     * object regardless of its contents, so the old code treated a genuinely
     * empty `userErrors` as if real errors were present and threw. These tests
     * replicate the real `ResponseAccess` wrapping (unlike the plain-array
     * mocks above) to guard against that false positive.
     */
    describe('userErrors as ResponseAccess object (real Shopify client shape)', function (): void {
        it('enables the pixel via webPixelCreate when userErrors is an empty ResponseAccess object', function (): void {
            $apiMock = Mockery::mock(BasicShopifyAPI::class);
            $apiMock->shouldReceive('graph')
                ->once()
                ->withArgs(fn (string $query) => str_contains($query, 'webPixelCreate'))
                ->andReturn([
                    'errors' => false,
                    'body' => new ResponseAccess([
                        'data' => [
                            'webPixelCreate' => [
                                'webPixel' => ['id' => 'gid://shopify/WebPixel/123'],
                                'userErrors' => [],
                            ],
                        ],
                    ]),
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

        it('enables the pixel via webPixelUpdate when userErrors is an empty ResponseAccess object', function (): void {
            $apiMock = Mockery::mock(BasicShopifyAPI::class);
            $apiMock->shouldReceive('graph')
                ->once()
                ->withArgs(fn (string $query) => str_contains($query, 'webPixelUpdate'))
                ->andReturn([
                    'errors' => false,
                    'body' => new ResponseAccess([
                        'data' => [
                            'webPixelUpdate' => [
                                'webPixel' => ['id' => 'gid://shopify/WebPixel/123'],
                                'userErrors' => [],
                            ],
                        ],
                    ]),
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

        it('disables the pixel via webPixelDelete when userErrors is an empty ResponseAccess object', function (): void {
            $apiMock = Mockery::mock(BasicShopifyAPI::class);
            $apiMock->shouldReceive('graph')
                ->once()
                ->withArgs(fn (string $query) => str_contains($query, 'webPixelDelete'))
                ->andReturn([
                    'errors' => false,
                    'body' => new ResponseAccess([
                        'data' => [
                            'webPixelDelete' => [
                                'deletedWebPixelId' => 'gid://shopify/WebPixel/123',
                                'userErrors' => [],
                            ],
                        ],
                    ]),
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

        it('still fails when userErrors is a non-empty ResponseAccess object', function (): void {
            $apiMock = Mockery::mock(BasicShopifyAPI::class);
            $apiMock->shouldReceive('graph')
                ->once()
                ->withArgs(fn (string $query) => str_contains($query, 'webPixelCreate'))
                ->andReturn([
                    'errors' => false,
                    'body' => new ResponseAccess([
                        'data' => [
                            'webPixelCreate' => [
                                'webPixel' => null,
                                'userErrors' => [
                                    ['field' => ['settings'], 'message' => 'Something went wrong.'],
                                ],
                            ],
                        ],
                    ]),
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
