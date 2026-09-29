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
use Illuminate\Support\Facades\Log;

/**
 * Fake platform driver whose upload behaviour is controlled per platform by the test.
 */
final class FakePlatformDriver implements ConversionPlatformContract
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

function fakeTrackingData(User $shop, ?string $gclid = 'gclid-1'): TrackingEventData
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
        gaClientId: null,
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
});
