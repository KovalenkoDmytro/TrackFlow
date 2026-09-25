<?php

declare(strict_types=1);

use App\Actions\Analytics\GetGoogleAdsAttribution;
use App\Enums\Platform;
use App\Models\ConversionActionMapping;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function conversionPayload(User $shop, array $overrides = []): array
{
    return array_merge([
        'shop_domain' => $shop->name,
        'tracking_secret' => 'shop-tracking-secret',
        'event' => 'add_to_cart',
        'value' => 12.5,
        'currency' => 'CAD',
    ], $overrides);
}

describe('occurred_at boundary validation', function (): void {
    beforeEach(function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00:00'));
        $this->shop = User::factory()->create(['tracking_secret' => 'shop-tracking-secret']);
    });

    afterEach(function (): void {
        $this->travelBack();
    });

    it('rejects a timestamp 10 minutes in the future', function (): void {
        $occurredAt = CarbonImmutable::now()->addMinutes(10)->toDateTimeString();

        $this->postJson('/api/conversions', conversionPayload($this->shop, ['occurred_at' => $occurredAt]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['occurred_at']);

        expect(TrackingEvent::query()->count())->toBe(0);
    });

    it('accepts a timestamp 4 minutes in the future, within the clock-skew tolerance', function (): void {
        $occurredAt = CarbonImmutable::now()->addMinutes(4)->toDateTimeString();

        $this->postJson('/api/conversions', conversionPayload($this->shop, ['occurred_at' => $occurredAt]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $event = TrackingEvent::query()->where('user_id', $this->shop->getKey())->firstOrFail();
        expect($event->occurred_at->toDateTimeString())->toBe($occurredAt);
    });

    it('rejects a timestamp 96 days in the past when a gclid is present', function (): void {
        $occurredAt = CarbonImmutable::now()->subDays(96)->toDateTimeString();

        $this->postJson('/api/conversions', conversionPayload($this->shop, [
            'gclid' => 'abc123',
            'occurred_at' => $occurredAt,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['occurred_at']);

        expect(TrackingEvent::query()->count())->toBe(0);
    });

    it('accepts a timestamp 90 days in the past, within the retention window, when a gclid is present', function (): void {
        $occurredAt = CarbonImmutable::now()->subDays(90)->toDateTimeString();

        $this->postJson('/api/conversions', conversionPayload($this->shop, [
            'gclid' => 'abc123',
            'occurred_at' => $occurredAt,
        ]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $event = TrackingEvent::query()->where('user_id', $this->shop->getKey())->firstOrFail();
        expect($event->occurred_at->toDateTimeString())->toBe($occurredAt);
    });

    it('accepts a timestamp 96 days in the past when no gclid is present, since the retention bound only concerns Google Ads matching', function (): void {
        $occurredAt = CarbonImmutable::now()->subDays(96)->toDateTimeString();

        $this->postJson('/api/conversions', conversionPayload($this->shop, ['occurred_at' => $occurredAt]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $event = TrackingEvent::query()->where('user_id', $this->shop->getKey())->firstOrFail();
        expect($event->occurred_at->toDateTimeString())->toBe($occurredAt);
    });

});

// Kept outside the frozen-clock describe above: the fallback in
// TrackEventRequest::toTrackingEventData() uses a bare `new DateTimeImmutable`
// (not Carbon), which does not honour Carbon::setTestNow(). Asserting this
// against a frozen clock would compare against the wrong instant, so this
// case is exercised against the real wall clock instead.
it('accepts a request with no occurred_at at all, defaulting to the current time', function (): void {
    $shop = User::factory()->create(['tracking_secret' => 'shop-tracking-secret']);
    $before = new DateTimeImmutable;

    $this->postJson('/api/conversions', conversionPayload($shop))
        ->assertOk()
        ->assertJsonPath('ok', true);

    $event = TrackingEvent::query()->where('user_id', $shop->getKey())->firstOrFail();
    expect($event->occurred_at)->not->toBeNull()
        ->and(abs($event->occurred_at->getTimestamp() - $before->getTimestamp()))->toBeLessThan(10);
});

describe('gclid trimming', function (): void {
    it('trims whitespace from gclid before persisting and hashing, and matches on the trimmed hash', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00:00'));

        $shop = User::factory()->create(['tracking_secret' => 'shop-tracking-secret']);
        $integration = PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAds,
            'active' => true,
            'credentials' => json_encode(['customer_id' => '1234567890']),
        ]);

        $this->postJson('/api/conversions', conversionPayload($shop, [
            'gclid' => '  abc123  ',
            'occurred_at' => '2026-09-25 10:00:00',
        ]))->assertOk();

        $event = TrackingEvent::query()->where('user_id', $shop->getKey())->firstOrFail();

        expect($event->gclid)->toBe('abc123')
            ->and($event->gclid_hash)->toBe(hash('sha256', 'abc123'));

        DB::table('google_ads_clicks')->insert([
            'platform_integration_id' => $integration->getKey(),
            'customer_id' => '1234567890',
            'gclid_hash' => hash('sha256', 'abc123'),
            'click_date' => '2026-09-25',
            'day_start_utc' => '2026-09-25 00:00:00',
            'checked_at' => now(),
        ]);

        $attribution = app(GetGoogleAdsAttribution::class)->handle(
            $shop,
            CarbonImmutable::parse('2026-09-25 00:00:00'),
            CarbonImmutable::parse('2026-09-25 23:59:59'),
        );

        expect($attribution['total'])->toBe(1)
            ->and($attribution['unverified_total'])->toBe(0);

        $this->travelBack();
    });

    it('treats a whitespace-only gclid as absent, storing null instead of an empty string', function (): void {
        $shop = User::factory()->create(['tracking_secret' => 'shop-tracking-secret']);

        $this->postJson('/api/conversions', conversionPayload($shop, ['gclid' => '   ']))
            ->assertOk();

        $event = TrackingEvent::query()->where('user_id', $shop->getKey())->firstOrFail();

        expect($event->gclid)->toBeNull()
            ->and($event->gclid_hash)->toBeNull();
    });

    it('does not upload a whitespace-only gclid to Google Ads', function (): void {
        Http::fake();

        $shop = User::factory()->create(['tracking_secret' => 'shop-tracking-secret']);
        $integration = PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAds,
            'active' => true,
            'credentials' => json_encode([
                'customer_id' => '1234567890',
                'oauth' => ['client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'refresh'],
            ]),
        ]);
        ConversionActionMapping::query()->create([
            'platform_integration_id' => $integration->getKey(),
            'event' => 'add_to_cart',
            'external_action_id' => 'customers/1234567890/conversionActions/1',
            'active' => true,
        ]);

        // ProcessTrackingEvent's `empty($data->gclid)` gate should treat this
        // as absent (TrackingEventData normalizes it to null at construction)
        // and skip the Google Ads integration entirely — no OAuth token
        // fetch, no uploadClickConversions call, no delivery attempt.
        $this->postJson('/api/conversions', conversionPayload($shop, ['gclid' => '   ']))
            ->assertOk();

        Http::assertNothingSent();

        expect(PlatformDelivery::query()->count())->toBe(0);
    });
});
