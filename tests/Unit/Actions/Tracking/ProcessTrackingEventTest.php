<?php

declare(strict_types=1);

use App\Actions\Tracking\ProcessTrackingEvent;
use App\Contracts\ConversionPlatformContract;
use App\Contracts\PlatformResolverContract;
use App\Data\TrackingEventData;
use App\Enums\Platform;
use App\Models\ConversionActionMapping;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fake platform driver whose upload behaviour is controlled per platform by the test.
 */
class FakePlatformDriver implements ConversionPlatformContract
{
    public int $uploads = 0;

    public function __construct(public bool|Throwable $result = true) {}

    public function testCredentials(array $credentials): void {}

    public function setupConversionActions(PlatformIntegration $integration): void {}

    public function uploadConversion(array $credentials, TrackingEventData $data, ConversionActionMapping $mapping): bool
    {
        $this->uploads++;

        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}

function fakeTrackingData(User $shop, ?string $gclid = 'gclid-1', ?string $gaClientId = null): TrackingEventData
{
    return new TrackingEventData(
        shopDomain: $shop->name,
        event: 'purchase',
        value: 10,
        currency: 'CAD',
        transactionId: 'T1',
        gclid: $gclid,
        fbp: 'fb.1.1',
        fbc: null,
        ttclid: null,
        gaClientId: $gaClientId,
        ip: '127.0.0.1',
        userAgent: 'test',
        idempotencyKey: 'idem-1',
        occurredAt: new DateTimeImmutable('2026-07-31T12:00:00+00:00'),
    );
}

function connectFakeIntegration(User $shop, Platform $platform, bool $active = true, bool $mapped = true): PlatformIntegration
{
    $integration = PlatformIntegration::query()->create([
        'user_id' => $shop->getKey(),
        'platform' => $platform,
        'active' => $active,
        'credentials' => json_encode(['token' => 'x']),
        'settings' => [],
    ]);

    if ($mapped) {
        ConversionActionMapping::query()->create([
            'platform_integration_id' => $integration->getKey(),
            'event' => 'purchase',
            'external_action_id' => 'Purchase',
            'active' => true,
        ]);
    }

    return $integration;
}

/** @param array<string, FakePlatformDriver> $drivers keyed by Platform value */
function bindFakeResolver(array $drivers): void
{
    app()->instance(PlatformResolverContract::class, new class($drivers) implements PlatformResolverContract
    {
        /** @param array<string, FakePlatformDriver> $drivers */
        public function __construct(private array $drivers) {}

        public function resolve(Platform $platform): ConversionPlatformContract
        {
            return $this->drivers[$platform->value];
        }
    });
}

describe('ProcessTrackingEvent', function (): void {
    beforeEach(function (): void {
        $this->shop = User::factory()->create();
    });

    it('still delivers to Meta when Google Ads fails, then rethrows for retry', function (): void {
        $google = connectFakeIntegration($this->shop, Platform::GoogleAds);
        $meta = connectFakeIntegration($this->shop, Platform::Meta);
        $metaDriver = new FakePlatformDriver(true);
        bindFakeResolver([
            'google_ads' => new FakePlatformDriver(new RuntimeException('google down')),
            'meta' => $metaDriver,
        ]);

        expect(fn () => app(ProcessTrackingEvent::class)->handle(fakeTrackingData($this->shop)))
            ->toThrow(RuntimeException::class, 'google down');

        expect($metaDriver->uploads)->toBe(1)
            ->and(PlatformDelivery::query()->where('platform_integration_id', $google->getKey())->value('status'))->toBe('failed')
            ->and(PlatformDelivery::query()->where('platform_integration_id', $meta->getKey())->value('status'))->toBe('delivered');
    });

    it('retries only the failed integration and does not duplicate delivery rows', function (): void {
        $google = connectFakeIntegration($this->shop, Platform::GoogleAds);
        connectFakeIntegration($this->shop, Platform::Meta);
        $googleDriver = new FakePlatformDriver(new RuntimeException('google down'));
        $metaDriver = new FakePlatformDriver(true);
        bindFakeResolver(['google_ads' => $googleDriver, 'meta' => $metaDriver]);

        try {
            app(ProcessTrackingEvent::class)->handle(fakeTrackingData($this->shop));
        } catch (RuntimeException) {
        }

        $googleDriver->result = true;
        app(ProcessTrackingEvent::class)->handle(fakeTrackingData($this->shop));

        expect($metaDriver->uploads)->toBe(1)
            ->and($googleDriver->uploads)->toBe(2)
            ->and(TrackingEvent::query()->count())->toBe(1)
            ->and(PlatformDelivery::query()->count())->toBe(2);

        $googleDelivery = PlatformDelivery::query()->where('platform_integration_id', $google->getKey())->firstOrFail();
        expect($googleDelivery->status)->toBe('delivered')->and($googleDelivery->attempts)->toBe(2);
    });

    it('persists the Google Ads partial failure reason without leaking the gclid', function (): void {
        $message = 'The click from the imported event is associated with a different Google Ads account (gclid-secret-12345).';
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://googleads.googleapis.com/*' => Http::response([
                'partialFailureError' => [
                    'code' => 3,
                    'message' => 'Errors in request: conversions[0]',
                    'details' => [[
                        '@type' => 'type.googleapis.com/google.ads.googleads.v24.errors.GoogleAdsFailure',
                        'errors' => [[
                            'errorCode' => ['conversionUploadError' => 'INVALID_CUSTOMER_FOR_CLICK'],
                            'message' => $message,
                            'location' => ['fieldPathElements' => [['fieldName' => 'conversions', 'index' => 0]]],
                        ]],
                    ]],
                ],
            ]),
        ]);

        $integration = connectFakeIntegration($this->shop, Platform::GoogleAds);
        $integration->update(['credentials' => json_encode([
            'customer_id' => '123-456-7890',
            'developer_token' => 'developer-token',
            'oauth' => ['client_id' => 'c', 'client_secret' => 's', 'refresh_token' => 'r'],
        ])]);

        app(ProcessTrackingEvent::class)->handle(fakeTrackingData($this->shop, gclid: 'gclid-secret-12345'));

        $delivery = PlatformDelivery::query()->firstOrFail();
        $body = json_decode((string) $delivery->response_body, true);

        expect($delivery->status)->toBe('partial_failure')
            ->and($delivery->response_code)->toBe(200)
            ->and($body['codes'])->toBe(['INVALID_CUSTOMER_FOR_CLICK'])
            ->and($body['message'])->toContain('different Google Ads account')
            ->and((string) $delivery->response_body)->not->toContain('gclid-secret-12345')
            ->and(strlen((string) $delivery->response_body))->toBeLessThanOrEqual(1024)
            ->and($integration->fresh()->last_error)->toBe('partial_failure: INVALID_CUSTOMER_FOR_CLICK');
    });

    it('does not re-send partial failures on retry', function (): void {
        connectFakeIntegration($this->shop, Platform::Meta);
        $metaDriver = new FakePlatformDriver(false);
        bindFakeResolver(['meta' => $metaDriver]);

        app(ProcessTrackingEvent::class)->handle(fakeTrackingData($this->shop));
        app(ProcessTrackingEvent::class)->handle(fakeTrackingData($this->shop));

        expect($metaDriver->uploads)->toBe(1)
            ->and(PlatformDelivery::query()->count())->toBe(1)
            ->and(PlatformDelivery::query()->value('status'))->toBe('partial_failure');
    });

    it('skips inactive integrations', function (): void {
        connectFakeIntegration($this->shop, Platform::Meta, active: false);
        $metaDriver = new FakePlatformDriver(true);
        bindFakeResolver(['meta' => $metaDriver]);

        app(ProcessTrackingEvent::class)->handle(fakeTrackingData($this->shop));

        expect($metaDriver->uploads)->toBe(0)->and(PlatformDelivery::query()->count())->toBe(0);
    });

    it('skips integrations without a mapping and logs a warning', function (): void {
        connectFakeIntegration($this->shop, Platform::Meta, mapped: false);
        $metaDriver = new FakePlatformDriver(true);
        bindFakeResolver(['meta' => $metaDriver]);
        Log::spy();

        app(ProcessTrackingEvent::class)->handle(fakeTrackingData($this->shop));

        expect($metaDriver->uploads)->toBe(0)->and(PlatformDelivery::query()->count())->toBe(0);
        Log::shouldHaveReceived('warning')->with('ProcessTrackingEvent: no mapping', Mockery::type('array'))->once();
    });

    it('skips Google Ads events without a gclid', function (): void {
        connectFakeIntegration($this->shop, Platform::GoogleAds);
        $googleDriver = new FakePlatformDriver(true);
        bindFakeResolver(['google_ads' => $googleDriver]);

        app(ProcessTrackingEvent::class)->handle(fakeTrackingData($this->shop, gclid: null));

        expect($googleDriver->uploads)->toBe(0)->and(PlatformDelivery::query()->count())->toBe(0);
    });

    it('skips GA4 events without a client id, creating no delivery row', function (): void {
        $ga4 = connectFakeIntegration($this->shop, Platform::GoogleAnalytics4);
        $driver = new FakePlatformDriver(false);
        bindFakeResolver(['ga4' => $driver]);

        app(ProcessTrackingEvent::class)->handle(fakeTrackingData($this->shop));

        expect($driver->uploads)->toBe(0)
            ->and(PlatformDelivery::query()->count())->toBe(0)
            ->and($ga4->fresh()->last_error)->toBeNull();
    });

    it('delivers GA4 events that have a client id', function (): void {
        connectFakeIntegration($this->shop, Platform::GoogleAnalytics4);
        $driver = new FakePlatformDriver(true);
        bindFakeResolver(['ga4' => $driver]);

        app(ProcessTrackingEvent::class)->handle(fakeTrackingData($this->shop, gaClientId: '123.456'));

        expect($driver->uploads)->toBe(1)
            ->and(PlatformDelivery::query()->value('status'))->toBe('delivered');
    });
});

describe('ProcessTrackingEvent Meta enrichment', function (): void {
    it('passes only the Meta driver an fbc recovered for the same shop and fbp', function (): void {
        $shop = User::factory()->create();
        connectFakeIntegration($shop, Platform::Meta);
        TrackingEvent::factory()->forUser($shop)->create(['fbp' => 'fb.1.1', 'fbc' => 'fb.1.9.CLICK']);

        $driver = new class extends FakePlatformDriver
        {
            public ?string $fbc = null;

            public function __construct()
            {
                parent::__construct(true);
            }

            public function uploadConversion(array $credentials, TrackingEventData $data, ConversionActionMapping $mapping): bool
            {
                $this->fbc = $data->fbc;

                return parent::uploadConversion($credentials, $data, $mapping);
            }
        };
        bindFakeResolver(['meta' => $driver]);

        app(ProcessTrackingEvent::class)->handle(fakeTrackingData($shop));

        expect($driver->fbc)->toBe('fb.1.9.CLICK');
    });
});
