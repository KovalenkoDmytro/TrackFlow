<?php

declare(strict_types=1);

use App\Data\TrackingEventData;
use App\Enums\Platform;
use App\Models\ConversionActionMapping;
use App\Models\PlatformIntegration;
use App\Models\User;
use App\Services\MetaClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function makeTrackingEventData(array $overrides = []): TrackingEventData
{
    return new TrackingEventData(
        shopDomain: $overrides['shopDomain'] ?? 'example.myshopify.com',
        event: $overrides['event'] ?? 'purchase',
        value: $overrides['value'] ?? 99.99,
        currency: $overrides['currency'] ?? 'USD',
        transactionId: array_key_exists('transactionId', $overrides) ? $overrides['transactionId'] : 'order-123',
        gclid: $overrides['gclid'] ?? null,
        fbp: array_key_exists('fbp', $overrides) ? $overrides['fbp'] : 'fb.1.111.222',
        fbc: array_key_exists('fbc', $overrides) ? $overrides['fbc'] : null,
        ttclid: $overrides['ttclid'] ?? null,
        gaClientId: $overrides['gaClientId'] ?? null,
        ip: array_key_exists('ip', $overrides) ? $overrides['ip'] : '127.0.0.1',
        userAgent: $overrides['userAgent'] ?? 'Mozilla/5.0',
        idempotencyKey: $overrides['idempotencyKey'] ?? 'idem-key-1',
        occurredAt: $overrides['occurredAt'] ?? new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        eventSourceUrl: $overrides['eventSourceUrl'] ?? null,
    );
}

describe('MetaClient::testCredentials', function (): void {
    it('succeeds when the Conversions API events endpoint responds with 200', function (): void {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['events_received' => 1], 200),
        ]);

        $client = new MetaClient;

        $client->testCredentials(['pixel_id' => '123', 'access_token' => 'valid-token']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains((string) $request->url(), 'graph.facebook.com/v21.0/123/events')
                && $body['data'][0]['event_name'] === 'TrackFlowConnectionTest'
                && $body['data'][0]['action_source'] === 'system_generated'
                && $body['data'][0]['user_data']['client_ip_address'] === '127.0.0.1'
                && $body['data'][0]['user_data']['client_user_agent'] === 'TrackFlow-ConnectionTest/1.0';
        });

        expect(true)->toBeTrue();
    });

    it('includes the test_event_code in the request body when present', function (): void {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['events_received' => 1], 200),
        ]);

        $client = new MetaClient;

        $client->testCredentials(['pixel_id' => '123', 'access_token' => 'valid-token', 'test_event_code' => 'TEST123']);

        Http::assertSent(fn ($request) => $request->data()['test_event_code'] === 'TEST123');
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

    it('throws an actionable, merchant-friendly message when Meta returns error code 100 (missing permission)', function (): void {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => '(#100) Missing Permission',
                    'type' => 'OAuthException',
                    'code' => 100,
                ],
            ], 400),
        ]);

        $client = new MetaClient;

        expect(fn () => $client->testCredentials(['pixel_id' => '123', 'access_token' => 'bad-scope-token']))
            ->toThrow(RuntimeException::class, 'Events Manager');
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

describe('MetaClient::uploadConversion payload', function (): void {
    function sendMetaEvent(array $overrides, string $metaEvent = 'Purchase', ?MetaClient $client = null): array
    {
        Http::fake(['https://graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'TRACE1'], 200)]);

        ($client ?? new MetaClient)->uploadConversion(
            ['pixel_id' => '123', 'access_token' => 'token'],
            makeTrackingEventData($overrides),
            new ConversionActionMapping(['external_action_id' => $metaEvent]),
        );

        $sent = [];
        Http::assertSent(function ($request) use (&$sent) {
            $sent = $request->data()['data'][0];

            return true;
        });

        return $sent;
    }

    it('sends event_source_url when available and omits it otherwise', function (): void {
        $with = sendMetaEvent(['eventSourceUrl' => 'https://store.test/products/x?utm=1#f']);
        $without = sendMetaEvent([]);

        expect($with['event_source_url'])->toBe('https://store.test/products/x')
            ->and($without)->not->toHaveKey('event_source_url');
    });

    it('omits custom_data when a non-purchase event has no real value', function (): void {
        $search = sendMetaEvent(['event' => 'search', 'value' => 0.0], 'Search');
        $view = sendMetaEvent(['event' => 'view_item', 'value' => 0.0], 'ViewContent');

        expect($search)->not->toHaveKey('custom_data')
            ->and($view)->not->toHaveKey('custom_data');
    });

    it('keeps value and currency for events with a positive value', function (): void {
        foreach (['AddToCart', 'InitiateCheckout', 'AddPaymentInfo', 'AddShippingInfo'] as $name) {
            $event = sendMetaEvent(['value' => 25.5, 'currency' => 'USD'], $name);

            expect($event['custom_data'])->toBe(['currency' => 'USD', 'value' => 25.5]);
        }
    });

    it('always sends value and currency for Purchase, even when the value is 0', function (): void {
        $event = sendMetaEvent(['value' => 0.0, 'currency' => 'CAD']);

        expect($event['custom_data'])->toBe(['currency' => 'CAD', 'value' => 0.0]);
    });

    it('does not change the event_id derivation', function (): void {
        $event = sendMetaEvent(['transactionId' => null, 'event' => 'search'], 'Search');

        expect($event['event_id'])->toBe(md5('Search|2026-01-01T00:00:00+00:00|idem-key-1'));
    });
});

describe('MetaClient event_time clamp', function (): void {
    beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'UTC')));
    afterEach(fn () => Carbon::setTestNow());

    it('caps a future event_time at now and logs only the skew', function (): void {
        Log::spy();

        $event = sendMetaEvent(['occurredAt' => new DateTimeImmutable('2026-10-08 12:05:00+00:00')]);

        expect($event['event_time'])->toBe(Carbon::now()->getTimestamp());
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $m, array $c): bool => $m === 'meta.event_time_clamped' && $c === ['skew_seconds' => 300],
        );
    });

    it('leaves a past event_time unchanged', function (): void {
        $past = new DateTimeImmutable('2026-10-08 11:00:00+00:00');

        expect(sendMetaEvent(['occurredAt' => $past])['event_time'])->toBe($past->getTimestamp());
    });

    it('keeps the dedup event_id identical regardless of now', function (): void {
        $future = new DateTimeImmutable('2026-10-08 12:05:00+00:00');
        $expected = md5('Search|2026-10-08T12:05:00+00:00|idem-key-1');

        $clamped = sendMetaEvent(['transactionId' => null, 'occurredAt' => $future], 'Search');
        Carbon::setTestNow(Carbon::parse('2026-10-08 13:00:00', 'UTC'));
        $notClamped = sendMetaEvent(['transactionId' => null, 'occurredAt' => $future], 'Search');

        expect($clamped['event_id'])->toBe($expected)
            ->and($notClamped['event_id'])->toBe($expected)
            ->and($notClamped['event_time'])->toBe($future->getTimestamp());
    });
});

describe('MetaClient::uploadConversion response handling', function (): void {
    $credentials = ['pixel_id' => '123', 'access_token' => 'secret-token-value'];
    $mapping = fn () => new ConversionActionMapping(['external_action_id' => 'Purchase']);

    it('records events_received and fbtrace_id as the delivery receipt on success', function () use ($credentials, $mapping): void {
        Http::fake(['https://graph.facebook.com/*' => Http::response(['events_received' => 1, 'messages' => [], 'fbtrace_id' => 'ABC123'], 200)]);
        $client = new MetaClient;

        expect($client->uploadConversion($credentials, makeTrackingEventData(), $mapping()))->toBeTrue()
            ->and(json_decode((string) $client->deliveryReceipt(), true))->toBe(['events_received' => 1, 'fbtrace_id' => 'ABC123'])
            ->and($client->deliveryReceipt())->not->toContain('secret-token-value')
            ->and($client->partialFailure())->toBeNull();
    });

    it('treats a 2xx with events_received 0 as a failure with a reason', function () use ($credentials, $mapping): void {
        Http::fake(['https://graph.facebook.com/*' => Http::response([
            'events_received' => 0,
            'messages' => ['Invalid event_source_url'],
            'fbtrace_id' => 'TRACE9',
        ], 200)]);
        $client = new MetaClient;

        expect($client->uploadConversion($credentials, makeTrackingEventData(), $mapping()))->toBeFalse()
            ->and($client->deliveryReceipt())->toBeNull()
            ->and($client->partialFailure()->primaryCode())->toBe('meta_no_events_received')
            ->and($client->partialFailure()->lastError())->toBe('partial_failure: meta_no_events_received')
            ->and(json_decode($client->partialFailure()->toJson(), true))->toMatchArray([
                'codes' => ['meta_no_events_received'],
                'message' => 'Invalid event_source_url',
                'fbtrace_id' => 'TRACE9',
            ]);
    });

    it('treats a 2xx without events_received (or non-JSON) as a failure', function () use ($credentials, $mapping): void {
        Http::fake(['https://graph.facebook.com/*' => Http::response('<html>ok</html>', 200)]);
        $client = new MetaClient;

        expect($client->uploadConversion($credentials, makeTrackingEventData(), $mapping()))->toBeFalse()
            ->and($client->partialFailure()->message)->toContain('events_received');
    });

    it('clears the previous outcome on the next call', function () use ($credentials, $mapping): void {
        $client = new MetaClient;
        Http::fake(['https://graph.facebook.com/*' => Http::sequence()
            ->push(['events_received' => 0], 200)
            ->push(['events_received' => 1], 200)]);
        $client->uploadConversion($credentials, makeTrackingEventData(), $mapping());
        $client->uploadConversion($credentials, makeTrackingEventData(), $mapping());

        expect($client->partialFailure())->toBeNull()
            ->and($client->deliveryReceipt())->not->toBeNull();
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
