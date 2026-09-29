<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\ConversionActionMapping;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Facades\Http;

describe('Meta delivery through POST /api/conversions', function (): void {
    beforeEach(function (): void {
        $this->shop = User::factory()->create(['tracking_secret' => 'shop-tracking-secret']);
        $this->integration = PlatformIntegration::query()->create([
            'user_id' => $this->shop->getKey(),
            'platform' => Platform::Meta,
            'active' => true,
            'credentials' => json_encode(['pixel_id' => '123', 'access_token' => 'super-secret-token']),
            'settings' => [],
        ]);
        ConversionActionMapping::query()->create([
            'platform_integration_id' => $this->integration->getKey(),
            'event' => 'add_to_cart',
            'external_action_id' => 'AddToCart',
            'active' => true,
        ]);
    });

    /** @param array<string, mixed> $overrides */
    function metaPayload(User $shop, array $overrides = []): array
    {
        return array_merge([
            'shop_domain' => $shop->name,
            'tracking_secret' => 'shop-tracking-secret',
            'event' => 'add_to_cart',
            'value' => 12.5,
            'currency' => 'CAD',
            'fbp' => 'fb.1.100.200',
            'idempotency_key' => 'k-'.uniqid(),
        ], $overrides);
    }

    it('sends a sanitised event_source_url and records a verifiable receipt', function (): void {
        Http::fake(['https://graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'TR1'], 200)]);

        $this->postJson('/api/conversions', metaPayload($this->shop, [
            'event_source_url' => 'https://store.test/products/x?fbclid=abc&token=secret#frag',
        ]))->assertOk();

        Http::assertSent(fn ($request) => $request->data()['data'][0]['event_source_url'] === 'https://store.test/products/x');

        $delivery = PlatformDelivery::query()->firstOrFail();
        expect($delivery->status)->toBe('delivered')
            ->and($delivery->response_code)->toBe(200)
            ->and(json_decode((string) $delivery->response_body, true))->toBe(['events_received' => 1, 'fbtrace_id' => 'TR1'])
            ->and((string) $delivery->response_body)->not->toContain('super-secret-token');
    });

    it('drops an http or malformed event_source_url without rejecting the event', function (): void {
        Http::fake(['https://graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);

        $this->postJson('/api/conversions', metaPayload($this->shop, ['event_source_url' => 'http://store.test/p']))->assertOk();
        $this->postJson('/api/conversions', metaPayload($this->shop, ['event_source_url' => 'not a url']))->assertOk();

        Http::assertSent(fn ($request) => ! array_key_exists('event_source_url', $request->data()['data'][0]));
    });

    it('marks a 2xx without events_received as a partial failure with the reason', function (): void {
        Http::fake(['https://graph.facebook.com/*' => Http::response(['events_received' => 0, 'messages' => ['bad event'], 'fbtrace_id' => 'TR2'], 200)]);

        $this->postJson('/api/conversions', metaPayload($this->shop))->assertOk();

        $delivery = PlatformDelivery::query()->firstOrFail();
        expect($delivery->status)->toBe('partial_failure')
            ->and(json_decode((string) $delivery->response_body, true)['codes'])->toBe(['meta_no_events_received'])
            ->and($this->integration->fresh()->last_error)->toBe('partial_failure: meta_no_events_received');
    });

    it('sends a recovered fbc to Meta without rewriting the stored event', function (): void {
        Http::fake(['https://graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        TrackingEvent::factory()->forUser($this->shop)->create(['fbp' => 'fb.1.100.200', 'fbc' => 'fb.1.111.LANDING']);

        $this->postJson('/api/conversions', metaPayload($this->shop, ['idempotency_key' => 'later']))->assertOk();

        Http::assertSent(fn ($request) => $request->data()['data'][0]['user_data']['fbc'] === 'fb.1.111.LANDING');
        expect(TrackingEvent::query()->where('idempotency_key', 'later')->firstOrFail()->fbc)->toBeNull();
    });

    it('does not borrow an fbc from another shop', function (): void {
        Http::fake(['https://graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        TrackingEvent::factory()->forUser(User::factory()->create())->create(['fbp' => 'fb.1.100.200', 'fbc' => 'fb.1.111.OTHER']);

        $this->postJson('/api/conversions', metaPayload($this->shop))->assertOk();

        Http::assertSent(fn ($request) => ! array_key_exists('fbc', $request->data()['data'][0]['user_data']));
    });
});
