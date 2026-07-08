<?php

declare(strict_types=1);

use App\Actions\Webhooks\CustomersDataRequestWebhook;

mutates(CustomersDataRequestWebhook::class);

describe('POST /webhook/customers-data-request', function (): void {
    beforeEach(function (): void {
        config(['shopify-app.api_secret' => 'test-shopify-secret']);

        $this->shopDomain = 'customers-data-request-shop.myshopify.com';

        $this->payload = [
            'shop_id' => 954889,
            'shop_domain' => $this->shopDomain,
            'customer' => [
                'id' => 191167,
                'email' => 'john@example.com',
                'phone' => '555-625-1199',
            ],
            'orders_requested' => [299938, 280263],
        ];

        $this->rawBody = json_encode($this->payload);
    });

    it('returns 200 for a request with a valid HMAC signature and shop domain header', function (): void {
        $signature = signShopifyWebhookPayload($this->rawBody);

        $response = $this->postJson('/webhook/customers-data-request', $this->payload, [
            'X-Shopify-Hmac-Sha256' => $signature,
            'X-Shopify-Shop-Domain' => $this->shopDomain,
        ]);

        $response->assertOk();
    });

    it('returns 401 when the HMAC signature is missing or invalid', function (): void {
        $response = $this->postJson('/webhook/customers-data-request', $this->payload, [
            'X-Shopify-Hmac-Sha256' => 'invalid-signature',
            'X-Shopify-Shop-Domain' => $this->shopDomain,
        ]);

        $response->assertUnauthorized();
    });

    it('returns 401 when the shop domain header is missing', function (): void {
        $signature = signShopifyWebhookPayload($this->rawBody);

        $response = $this->postJson('/webhook/customers-data-request', $this->payload, [
            'X-Shopify-Hmac-Sha256' => $signature,
        ]);

        $response->assertUnauthorized();
    });
});
