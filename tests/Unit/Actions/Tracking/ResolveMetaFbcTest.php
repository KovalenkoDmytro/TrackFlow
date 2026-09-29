<?php

declare(strict_types=1);

use App\Actions\Tracking\ResolveMetaFbc;
use App\Data\TrackingEventData;
use App\Models\TrackingEvent;
use App\Models\User;

function fbcLookupData(?string $fbp = 'fb.1.100.200', ?string $fbc = null): TrackingEventData
{
    return new TrackingEventData(
        shopDomain: 'shop.myshopify.com',
        event: 'add_to_cart',
        value: 0,
        currency: 'CAD',
        transactionId: null,
        gclid: null,
        fbp: $fbp,
        fbc: $fbc,
        ttclid: null,
        gaClientId: null,
        ip: '127.0.0.1',
        userAgent: 'test',
        idempotencyKey: 'k',
        occurredAt: new DateTimeImmutable('2026-07-31T12:00:00+00:00'),
    );
}

describe('ResolveMetaFbc', function (): void {
    beforeEach(function (): void {
        $this->shop = User::factory()->create();
    });

    it('recovers the most recent fbc for the same shop and fbp', function (): void {
        TrackingEvent::factory()->forUser($this->shop)->create(['fbp' => 'fb.1.100.200', 'fbc' => 'fb.1.1.OLD', 'created_at' => now()->subDays(3)]);
        TrackingEvent::factory()->forUser($this->shop)->create(['fbp' => 'fb.1.100.200', 'fbc' => 'fb.1.2.NEW', 'created_at' => now()->subDay()]);
        TrackingEvent::factory()->forUser($this->shop)->create(['fbp' => 'fb.1.100.200', 'fbc' => null, 'created_at' => now()]);

        $result = ResolveMetaFbc::run(fbcLookupData(), (int) $this->shop->getKey());

        expect($result->fbc)->toBe('fb.1.2.NEW')
            ->and($result->fbp)->toBe('fb.1.100.200');
    });

    it('returns the event unchanged when nothing matches', function (): void {
        TrackingEvent::factory()->forUser($this->shop)->create(['fbp' => 'fb.1.999.999', 'fbc' => 'fb.1.1.OTHER']);
        TrackingEvent::factory()->forUser($this->shop)->create(['fbp' => 'fb.1.100.200', 'fbc' => '']);

        expect(ResolveMetaFbc::run(fbcLookupData(), (int) $this->shop->getKey())->fbc)->toBeNull();
    });

    it('never crosses shops', function (): void {
        $other = User::factory()->create();
        TrackingEvent::factory()->forUser($other)->create(['fbp' => 'fb.1.100.200', 'fbc' => 'fb.1.1.OTHERSHOP']);

        expect(ResolveMetaFbc::run(fbcLookupData(), (int) $this->shop->getKey())->fbc)->toBeNull();
    });

    it('ignores events older than the lookback window and honours the config', function (): void {
        TrackingEvent::factory()->forUser($this->shop)->create(['fbp' => 'fb.1.100.200', 'fbc' => 'fb.1.1.STALE', 'created_at' => now()->subDays(8)]);

        expect(ResolveMetaFbc::run(fbcLookupData(), (int) $this->shop->getKey())->fbc)->toBeNull();

        config(['tracking.fbc_lookback_days' => 10]);

        expect(ResolveMetaFbc::run(fbcLookupData(), (int) $this->shop->getKey())->fbc)->toBe('fb.1.1.STALE');
    });

    it('keeps an fbc the event already has and skips events without an fbp', function (): void {
        TrackingEvent::factory()->forUser($this->shop)->create(['fbp' => 'fb.1.100.200', 'fbc' => 'fb.1.1.STORED']);

        expect(ResolveMetaFbc::run(fbcLookupData(fbc: 'fb.1.5.OWN'), (int) $this->shop->getKey())->fbc)->toBe('fb.1.5.OWN')
            ->and(ResolveMetaFbc::run(fbcLookupData(fbp: null), (int) $this->shop->getKey())->fbc)->toBeNull();
    });
});
