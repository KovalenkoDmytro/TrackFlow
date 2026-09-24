<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Independent adversarial coverage for the /api/* session-token auth path,
 * on top of tests/Feature/Auth/SessionTokenAuthenticationTest.php — probes
 * cases not already covered there: unexpected claims, malformed dest values,
 * wrong-secret signatures, cookie+bearer precedence, and the dev bypass edge
 * cases (wrong shop, and bearer token present alongside the flag).
 */
describe('AuthenticateShopifySessionToken: adversarial coverage', function (): void {
    it('still authenticates a token carrying an extra, unexpected claim', function (): void {
        $shop = User::factory()->create();
        $token = $this->shopifySessionToken($shop, ['unexpected_claim' => 'anything']);

        $response = $this->withToken($token)->getJson('/api/shop-status');

        $response->assertOk();
        $response->assertJsonPath('shop.name', $shop->name);
    });

    it('rejects a token whose dest has a path appended', function (): void {
        $shop = User::factory()->create();
        $token = $this->shopifySessionToken($shop, [
            'dest' => "https://{$shop->name}/admin",
            'iss' => "https://{$shop->name}/admin",
        ]);

        $response = $this->withToken($token)->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'session_token_invalid']);
    });

    it('rejects a token whose dest has a port appended', function (): void {
        $shop = User::factory()->create();
        $token = $this->shopifySessionToken($shop, [
            'dest' => "https://{$shop->name}:8443",
        ]);

        $response = $this->withToken($token)->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'session_token_invalid']);
    });

    it('rejects a token signed with a completely different secret', function (): void {
        $shop = User::factory()->create();

        $claims = [
            'iss' => "https://{$shop->name}/admin",
            'dest' => "https://{$shop->name}",
            'aud' => (string) config('shopify-app.api_key'),
            'sub' => (string) $shop->getKey(),
            'exp' => time() + 60,
            'nbf' => time(),
            'iat' => time(),
            'jti' => Str::random(16),
            'sid' => Str::random(16),
        ];

        $header = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9';
        $payload = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signingInput = "{$header}.{$payload}";
        $signature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $signingInput, 'a-totally-different-secret-the-app-never-configured', true),
        ), '+/', '-_'), '=');

        $token = "{$signingInput}.{$signature}";

        $response = $this->withToken($token)->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'session_token_invalid']);
    });

    it('lets a valid bearer token authenticate as its own shop even when a session cookie for a different shop is also present', function (): void {
        $sessionShop = User::factory()->create();
        $tokenShop = User::factory()->create();

        $response = $this->actingAs($sessionShop)
            ->withToken($this->shopifySessionToken($tokenShop))
            ->getJson('/api/shop-status');

        $response->assertOk();
        $response->assertJsonPath('shop.name', $tokenShop->name);
    });

    it('rejects an invalid bearer token even when a valid session cookie for some shop is also present', function (): void {
        $sessionShop = User::factory()->create();

        $response = $this->actingAs($sessionShop)
            ->withToken('not-a-jwt-at-all')
            ->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'session_token_invalid']);
    });

    it('never bypasses via the dev flag when the configured dev shop domain does not match any installed shop', function (): void {
        app()->instance('env', 'local');
        config([
            'shopify-app.dev_auth_bypass' => true,
            'shopify-app.dev_shop_domain' => 'not-an-installed-shop.myshopify.com',
        ]);

        $response = $this->withoutToken()->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'shop_not_installed']);
    });

    it('never engages the dev bypass when a bearer token is present, even with the flag on and a valid dev shop configured', function (): void {
        $devShop = User::factory()->create();
        $realShop = User::factory()->create();

        app()->instance('env', 'local');
        config([
            'shopify-app.dev_auth_bypass' => true,
            'shopify-app.dev_shop_domain' => $devShop->name,
        ]);

        $response = $this->withToken($this->shopifySessionToken($realShop))->getJson('/api/shop-status');

        $response->assertOk();
        $response->assertJsonPath('shop.name', $realShop->name);
    });

    it('never engages the dev bypass for a garbage bearer token, even with the flag on and a valid dev shop configured', function (): void {
        $devShop = User::factory()->create();

        app()->instance('env', 'local');
        config([
            'shopify-app.dev_auth_bypass' => true,
            'shopify-app.dev_shop_domain' => $devShop->name,
        ]);

        $response = $this->withToken('not-a-jwt-at-all')->getJson('/api/shop-status');

        $response->assertUnauthorized();
        $response->assertJson(['code' => 'session_token_invalid']);
    });
});
