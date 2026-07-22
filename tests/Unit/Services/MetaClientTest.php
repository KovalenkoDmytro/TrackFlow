<?php

declare(strict_types=1);

use App\Data\TrackingEventData;
use App\Enums\Platform;
use App\Models\ConversionActionMapping;
use App\Models\PlatformIntegration;
use App\Models\User;
use App\Services\MetaClient;
use Illuminate\Support\Facades\Http;

function makeTrackingEventData(array $overrides = []): TrackingEventData
{
    return new TrackingEventData(
        shopDomain: $overrides['shopDomain'] ?? 'example.myshopify.com',
        event: $overrides['event'] ?? 'purchase',
        value: $overrides['value'] ?? 99.99,
        currency: $overrides['currency'] ?? 'USD',
        transactionId: $overrides['transactionId'] ?? 'order-123',
        gclid: $overrides['gclid'] ?? null,
        fbp: array_key_exists('fbp', $overrides) ? $overrides['fbp'] : 'fb.1.111.222',
        fbc: array_key_exists('fbc', $overrides) ? $overrides['fbc'] : null,
        ttclid: $overrides['ttclid'] ?? null,
        gaClientId: $overrides['gaClientId'] ?? null,
        ip: array_key_exists('ip', $overrides) ? $overrides['ip'] : '127.0.0.1',
        userAgent: $overrides['userAgent'] ?? 'Mozilla/5.0',
        idempotencyKey: $overrides['idempotencyKey'] ?? 'idem-key-1',
        occurredAt: $overrides['occurredAt'] ?? new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
    );
}

describe('MetaClient::testCredentials', function (): void {
    it('succeeds when the Graph API responds with 200', function (): void {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['id' => '123', 'name' => 'My Pixel'], 200),
        ]);

        $client = new MetaClient;

        $client->testCredentials(['pixel_id' => '123', 'access_token' => 'valid-token']);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'graph.facebook.com/v21.0/123'));

        expect(true)->toBeTrue();
    });

    it('throws a RuntimeException with the Meta error message on failure', function (): void {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token.'],
            ], 400),
        ]);

        $client = new MetaClient;

        expect(fn () => $client->testCredentials(['pixel_id' => '123', 'access_token' => 'bad-token']))
            ->toThrow(RuntimeException::class, 'Invalid OAuth access token.');
    });
});

describe('MetaClient::uploadConversion', function (): void {
    it('returns false when there is no fbp, fbc, or ip to attribute the event to', function (): void {
        $client = new MetaClient;
        $data = makeTrackingEventData(['fbp' => null, 'fbc' => null, 'ip' => null]);
        $mapping = new ConversionActionMapping(['external_action_id' => 'Purchase']);

        $result = $client->uploadConversion(['pixel_id' => '123', 'access_token' => 'token'], $data, $mapping);

        expect($result)->toBeFalse();
    });

    it('sends the event to the Conversions API and returns true on success', function (): void {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['events_received' => 1], 200),
        ]);

        $client = new MetaClient;
        $data = makeTrackingEventData();
        $mapping = new ConversionActionMapping(['external_action_id' => 'Purchase']);

        $result = $client->uploadConversion(['pixel_id' => '123', 'access_token' => 'token'], $data, $mapping);

        expect($result)->toBeTrue();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['data'][0]['event_name'] === 'Purchase'
                && $body['data'][0]['event_id'] === 'order-123'
                && $body['data'][0]['user_data']['fbp'] === 'fb.1.111.222'
                && $body['data'][0]['action_source'] === 'website';
        });
    });

    it('throws a RuntimeException on a non-2xx response', function (): void {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid parameter'],
            ], 400),
        ]);

        $client = new MetaClient;
        $data = makeTrackingEventData();
        $mapping = new ConversionActionMapping(['external_action_id' => 'Purchase']);

        expect(fn () => $client->uploadConversion(['pixel_id' => '123', 'access_token' => 'token'], $data, $mapping))
            ->toThrow(RuntimeException::class, 'Invalid parameter');
    });

    it('includes the test_event_code in the request body when present', function (): void {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['events_received' => 1], 200),
        ]);

        $client = new MetaClient;
        $data = makeTrackingEventData();
        $mapping = new ConversionActionMapping(['external_action_id' => 'Purchase']);

        $client->uploadConversion(
            ['pixel_id' => '123', 'access_token' => 'token', 'test_event_code' => 'TEST123'],
            $data,
            $mapping,
        );

        Http::assertSent(fn ($request) => $request->data()['test_event_code'] === 'TEST123');
    });
});

describe('MetaClient::setupConversionActions', function (): void {
    it('upserts a ConversionActionMapping for every Shopify event', function (): void {
        $shop = User::factory()->create();
        $integration = PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::Meta,
            'active' => true,
            'credentials' => json_encode(['pixel_id' => '123', 'access_token' => 'token']),
            'settings' => [],
        ]);

        $client = new MetaClient;
        $client->setupConversionActions($integration);

        $mappings = ConversionActionMapping::query()
            ->where('platform_integration_id', $integration->getKey())
            ->pluck('external_action_id', 'event');

        expect($mappings->all())->toEqualCanonicalizing([
            'purchase' => 'Purchase',
            'add_to_cart' => 'AddToCart',
            'begin_checkout' => 'InitiateCheckout',
            'view_item' => 'ViewContent',
            'search' => 'Search',
            'add_payment_info' => 'AddPaymentInfo',
            'view_cart' => 'ViewCart',
            'remove_from_cart' => 'RemoveFromCart',
            'add_shipping_info' => 'AddShippingInfo',
        ]);
    });
});
