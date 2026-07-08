<?php

declare(strict_types=1);

use App\Actions\Webhooks\ShopRedactWebhook;
use App\Enums\Platform;
use App\Enums\TrackingEventType;
use App\Models\ConversionActionMapping;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;

mutates(ShopRedactWebhook::class);

describe('POST /webhook/shop-redact', function (): void {
    beforeEach(function (): void {
        config(['shopify-app.api_secret' => 'test-shopify-secret']);

        $this->shopDomain = 'shop-redact-shop.myshopify.com';

        $this->payload = [
            'shop_id' => 954889,
            'shop_domain' => $this->shopDomain,
        ];

        $this->rawBody = json_encode($this->payload);
    });

    it('returns 200 for a request with a valid HMAC signature and shop domain header', function (): void {
        User::factory()->create(['name' => $this->shopDomain]);

        $signature = signShopifyWebhookPayload($this->rawBody);

        $response = $this->postJson('/webhook/shop-redact', $this->payload, [
            'X-Shopify-Hmac-Sha256' => $signature,
            'X-Shopify-Shop-Domain' => $this->shopDomain,
        ]);

        $response->assertOk();
    });

    it('returns 401 when the HMAC signature is missing or invalid and does not delete any data', function (): void {
        $shop = User::factory()->create(['name' => $this->shopDomain]);
        TrackingEvent::factory()->forUser($shop)->create();

        $response = $this->postJson('/webhook/shop-redact', $this->payload, [
            'X-Shopify-Hmac-Sha256' => 'invalid-signature',
            'X-Shopify-Shop-Domain' => $this->shopDomain,
        ]);

        $response->assertUnauthorized();

        expect(User::query()->whereKey($shop->getKey())->exists())->toBeTrue()
            ->and(TrackingEvent::query()->where('user_id', $shop->getKey())->count())->toBe(1);
    });

    it('returns 401 when the shop domain header is missing', function (): void {
        $signature = signShopifyWebhookPayload($this->rawBody);

        $response = $this->postJson('/webhook/shop-redact', $this->payload, [
            'X-Shopify-Hmac-Sha256' => $signature,
        ]);

        $response->assertUnauthorized();
    });

    it('deletes the shop and all associated data on a valid signed request', function (): void {
        $shop = User::factory()->create(['name' => $this->shopDomain]);

        $trackingEvent = TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::Purchase)->create();

        $integration = PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAds,
            'active' => true,
            'credentials' => json_encode(['token' => 'secret']),
            'settings' => [],
        ]);

        $mapping = ConversionActionMapping::query()->create([
            'platform_integration_id' => $integration->getKey(),
            'event' => TrackingEventType::Purchase->value,
            'external_action_id' => 'customers/123/conversionActions/456',
            'active' => true,
        ]);

        $delivery = PlatformDelivery::query()->create([
            'tracking_event_id' => $trackingEvent->getKey(),
            'platform_integration_id' => $integration->getKey(),
            'platform' => Platform::GoogleAds->value,
            'status' => 'delivered',
            'attempts' => 1,
        ]);

        $signature = signShopifyWebhookPayload($this->rawBody);

        $response = $this->postJson('/webhook/shop-redact', $this->payload, [
            'X-Shopify-Hmac-Sha256' => $signature,
            'X-Shopify-Shop-Domain' => $this->shopDomain,
        ]);

        $response->assertOk();

        expect(User::query()->whereKey($shop->getKey())->exists())->toBeFalse()
            ->and(TrackingEvent::query()->whereKey($trackingEvent->getKey())->exists())->toBeFalse()
            ->and(PlatformIntegration::query()->whereKey($integration->getKey())->exists())->toBeFalse()
            ->and(ConversionActionMapping::query()->whereKey($mapping->getKey())->exists())->toBeFalse()
            ->and(PlatformDelivery::query()->whereKey($delivery->getKey())->exists())->toBeFalse();
    });

    it('returns 200 and is a no-op when the shop domain is unknown', function (): void {
        $signature = signShopifyWebhookPayload($this->rawBody);

        expect(User::query()->where('name', $this->shopDomain)->exists())->toBeFalse();

        $response = $this->postJson('/webhook/shop-redact', $this->payload, [
            'X-Shopify-Hmac-Sha256' => $signature,
            'X-Shopify-Shop-Domain' => $this->shopDomain,
        ]);

        $response->assertOk();

        expect(User::query()->where('name', $this->shopDomain)->exists())->toBeFalse();
    });
});
