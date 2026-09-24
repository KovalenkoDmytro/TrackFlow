<?php

declare(strict_types=1);

use App\Models\User;

describe('VerifyShopifyEmbeddedLaunch: gates the embedded shell routes', function (): void {
    it('serves the app when the query string carries a valid HMAC and a fresh timestamp', function (): void {
        $shop = User::factory()->create();

        $query = $this->signShopifyLaunchQuery([
            'shop' => $shop->name,
            'host' => base64_encode("{$shop->name}/admin"),
            'timestamp' => (string) time(),
        ]);

        $response = $this->get('/?'.http_build_query($query));

        $response->assertOk();
    });

    it('bounces (and never logs in) a request with a `shop` param but no HMAC at all — the original bypass', function (): void {
        $shop = User::factory()->create();

        $response = $this->get('/?shop='.$shop->name);

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('/shopify/session-token-bounce');
        $this->assertGuest();
    });

    it('bounces a request whose timestamp is outside the 5-minute window', function (): void {
        $shop = User::factory()->create();

        $query = $this->signShopifyLaunchQuery([
            'shop' => $shop->name,
            'timestamp' => (string) (time() - 400),
        ]);

        $response = $this->get('/?'.http_build_query($query));

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('/shopify/session-token-bounce');
    });

    it('bounces a request where the HMAC was signed for a different shop than the `shop` param claims', function (): void {
        $shopA = User::factory()->create();
        $shopB = User::factory()->create();

        $query = $this->signShopifyLaunchQuery([
            'shop' => $shopA->name,
            'timestamp' => (string) time(),
        ]);
        $query['shop'] = $shopB->name;

        $response = $this->get('/?'.http_build_query($query));

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('/shopify/session-token-bounce');
        $this->assertGuest();
    });

    it('serves the app when the query string carries a valid id_token', function (): void {
        $shop = User::factory()->create();

        $response = $this->get('/?id_token='.$this->shopifySessionToken($shop));

        $response->assertOk();
    });

    it('redirects to the authenticate route when the shop is verified but not installed', function (): void {
        $query = $this->signShopifyLaunchQuery([
            'shop' => 'never-installed-shop.myshopify.com',
            'timestamp' => (string) time(),
        ]);

        $response = $this->get('/?'.http_build_query($query));

        $response->assertRedirect(route('authenticate', [
            'shop' => 'never-installed-shop.myshopify.com',
            'host' => null,
        ]));
    });

    it('never authenticates the session — the gate is stateless', function (): void {
        $shop = User::factory()->create();

        $query = $this->signShopifyLaunchQuery([
            'shop' => $shop->name,
            'timestamp' => (string) time(),
        ]);

        $this->get('/?'.http_build_query($query))->assertOk();

        $this->assertGuest();
    });

    it('completes the full bounce-page cycle without a redirect loop: stale launch bounces, then a valid id_token reload is accepted', function (): void {
        $shop = User::factory()->create();

        $staleQuery = $this->signShopifyLaunchQuery([
            'shop' => $shop->name,
            'timestamp' => (string) (time() - 400),
        ]);

        $bounced = $this->get('/?'.http_build_query($staleQuery));
        $bounced->assertRedirect();
        expect($bounced->headers->get('Location'))->toContain('/shopify/session-token-bounce');

        // Simulates the bounce page reloading the original URL with a fresh
        // id_token appended by App Bridge.
        $reloaded = $this->get('/?'.http_build_query([...$staleQuery, 'id_token' => $this->shopifySessionToken($shop)]));

        $reloaded->assertOk();
    });

    it('accepts a valid id_token even when the URL still carries the stale hmac/timestamp that triggered the bounce', function (): void {
        $shop = User::factory()->create();

        $staleQuery = $this->signShopifyLaunchQuery([
            'shop' => $shop->name,
            'timestamp' => (string) (time() - 400),
        ]);

        $response = $this->get('/?'.http_build_query([...$staleQuery, 'id_token' => $this->shopifySessionToken($shop)]));

        $response->assertOk();
    });
});
