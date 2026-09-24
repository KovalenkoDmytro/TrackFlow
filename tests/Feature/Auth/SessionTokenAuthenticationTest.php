<?php

declare(strict_types=1);

use App\Models\User;

describe('AuthenticateShopifySessionToken: /api/* bearer-token authentication', function (): void {
    it('authenticates a request carrying a valid session token', function (): void {
        $shop = User::factory()->create();

        $response = $this->withToken($this->shopifySessionToken($shop))->getJson('/api/shop-status');

        $response->assertOk();
        $response->assertJsonPath('shop.name', $shop->name);
    });

    it('rejects a request with no Authorization header as missing, with the retry header', function (): void {
        $response = $this->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'session_token_missing']);
        $response->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');
    });

    it('rejects a bearer token that is not a well-formed JWT as invalid, with the retry header', function (): void {
        $response = $this->withToken('not-a-jwt-at-all')->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'session_token_invalid']);
        $response->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');
    });

    it('rejects a token with a bad signature as invalid, with the retry header', function (): void {
        $shop = User::factory()->create();
        $token = $this->shopifySessionToken($shop);
        $tampered = substr($token, 0, -4).'abcd';

        $response = $this->withToken($tampered)->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'session_token_invalid']);
        $response->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');
    });

    it('rejects an expired token as expired, with the retry header', function (): void {
        $shop = User::factory()->create();
        $token = $this->shopifySessionToken($shop, [
            'exp' => time() - 3600,
            'nbf' => time() - 3660,
            'iat' => time() - 3660,
        ]);

        $response = $this->withToken($token)->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'session_token_expired']);
        $response->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');
    });

    it('treats a future-skewed nbf/iat as expired, with the retry header', function (): void {
        $shop = User::factory()->create();
        $token = $this->shopifySessionToken($shop, [
            'nbf' => time() + 3600,
            'iat' => time() + 3600,
        ]);

        $response = $this->withToken($token)->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'session_token_expired']);
        $response->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');
    });

    it('rejects a token with the wrong audience as invalid, with the retry header', function (): void {
        $shop = User::factory()->create();
        $token = $this->shopifySessionToken($shop, ['aud' => 'some-other-api-key']);

        $response = $this->withToken($token)->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'session_token_invalid']);
        $response->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');
    });

    it('rejects a token whose issuer is not exactly `dest`+`/admin` as invalid, with the retry header', function (): void {
        $shop = User::factory()->create();
        $token = $this->shopifySessionToken($shop, [
            'iss' => "https://{$shop->name}/admin/extra-path",
        ]);

        $response = $this->withToken($token)->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'session_token_invalid']);
        $response->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');
    });

    it('rejects a token missing the sid claim as invalid, with the retry header', function (): void {
        $shop = User::factory()->create();
        $token = $this->shopifySessionToken($shop, ['sid' => null]);

        $response = $this->withToken($token)->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'session_token_invalid']);
        $response->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');
    });

    it('never 500s on a garbage/malformed token payload', function (): void {
        $header = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9';
        $garbagePayload = rtrim(strtr(base64_encode('not-json-at-all'), '+/', '-_'), '=');
        $token = "{$header}.{$garbagePayload}.somesignature";

        $response = $this->withToken($token)->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'session_token_invalid']);
    });

    it('rejects a well-formed token for a shop that was never installed, with no retry header', function (): void {
        $unknownShop = User::factory()->make(['name' => 'never-installed-shop.myshopify.com']);
        $token = $this->shopifySessionToken($unknownShop);

        $response = $this->withToken($token)->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'shop_not_installed']);
        $response->assertHeaderMissing('X-Shopify-Retry-Invalid-Session-Request');
    });

    it('rejects a token for a soft-deleted shop, with no retry header', function (): void {
        $shop = User::factory()->create();
        $token = $this->shopifySessionToken($shop);
        $shop->delete();

        $response = $this->withToken($token)->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'shop_not_installed']);
        $response->assertHeaderMissing('X-Shopify-Retry-Invalid-Session-Request');
    });

    it('closes the original bypass: a real web session cookie alone never authenticates /api/*', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'session_token_missing']);
    });

    it('never sets a session cookie on an /api/* response', function (): void {
        $shop = User::factory()->create();

        $response = $this->withToken($this->shopifySessionToken($shop))->getJson('/api/shop-status');

        $response->assertOk();
        expect($response->headers->has('Set-Cookie'))->toBeFalse();
    });

    it('authenticates each request independently with no cross-request state leak', function (): void {
        $shopA = User::factory()->create();
        $shopB = User::factory()->create();

        $responseA = $this->withToken($this->shopifySessionToken($shopA))->getJson('/api/shop-status');
        $responseA->assertOk();
        $responseA->assertJsonPath('shop.name', $shopA->name);

        $responseB = $this->withToken($this->shopifySessionToken($shopB))->getJson('/api/shop-status');
        $responseB->assertOk();
        $responseB->assertJsonPath('shop.name', $shopB->name);

        // withToken() sets a *default* header that otherwise persists on every
        // subsequent call from this same test client — clear it explicitly so
        // this call genuinely carries no Authorization header.
        $responseNone = $this->withoutToken()->getJson('/api/shop-status');
        $responseNone->assertUnauthorized();
        $responseNone->assertJson(['code' => 'session_token_missing']);
    });
});
