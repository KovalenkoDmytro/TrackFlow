<?php

declare(strict_types=1);

use App\Actions\Analytics\GetPlatformDeliveryStatsForPeriod;
use App\Actions\Tracking\ProcessTrackingEvent;
use App\Data\TrackingEventData;
use App\Enums\Platform;
use App\Enums\TrackingEventType;
use App\Models\ConversionActionMapping;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Services\GoogleAdsConversionWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function expIntegration(User $shop): PlatformIntegration
{
    $integration = PlatformIntegration::query()->create([
        'user_id' => $shop->getKey(),
        'platform' => Platform::GoogleAds,
        'active' => true,
        'credentials' => json_encode([
            'customer_id' => '123-456-7890',
            'developer_token' => 'dev',
            'oauth' => ['client_id' => 'c', 'client_secret' => 's', 'refresh_token' => 'r'],
        ]),
    ]);
    ConversionActionMapping::query()->create([
        'platform_integration_id' => $integration->getKey(),
        'event' => 'purchase',
        'external_action_id' => 'customers/1234567890/conversionActions/1',
        'active' => true,
    ]);

    return $integration;
}

function expSyncDays(PlatformIntegration $integration, int $days): void
{
    foreach (range(1, $days) as $d) {
        DB::table('google_ads_click_syncs')->insert([
            'platform_integration_id' => $integration->getKey(), 'customer_id' => '1234567890',
            'click_date' => now()->subDays($d)->toDateString(), 'checked_at' => now(),
        ]);
    }
}

function expUploads(): int
{
    return Http::recorded(fn ($request) => str_contains($request->url(), 'uploadClickConversions'))->count();
}

function expBaseData(User $shop, string $gclid, string $key): TrackingEventData
{
    return new TrackingEventData(
        shopDomain: $shop->name, event: 'purchase', value: 10, currency: 'CAD', transactionId: 'T-'.$key,
        gclid: $gclid, fbp: null, fbc: null, ttclid: null, gaClientId: null, ip: '127.0.0.1',
        userAgent: 'test', idempotencyKey: $key, occurredAt: new DateTimeImmutable('now'),
    );
}

/** $uploadErrorCode null = upload accepted, otherwise the partialFailureError code(s). */
function expFakeGoogle(string|array|null $uploadErrorCode = null, ?int $windowDays = 30, bool $searchFails = false): void
{
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
        '*googleAds:search' => $searchFails
            ? Http::response(['error' => ['message' => 'boom']], 500)
            : Http::response(['results' => $windowDays === null ? [] : [[
                'conversionAction' => ['clickThroughLookbackWindowDays' => (string) $windowDays],
            ]]]),
        '*uploadClickConversions' => $uploadErrorCode === null
            ? Http::response([])
            : Http::response(['partialFailureError' => [
                'code' => 3,
                'message' => 'Errors in request: conversions[0]',
                'details' => [['errors' => array_map(fn (string $code): array => [
                    'errorCode' => ['conversionUploadError' => $code],
                    'message' => 'The click is too old.',
                ], (array) $uploadErrorCode)]],
            ]]),
    ]);
}

function expData(User $shop, int $ageDays, string $key = 'k1'): TrackingEventData
{
    $data = expBaseData($shop, 'gclid-'.$key, $key);

    return new TrackingEventData(
        shopDomain: $data->shopDomain, event: $data->event, value: $data->value, currency: $data->currency,
        transactionId: $data->transactionId, gclid: $data->gclid, fbp: null, fbc: null, ttclid: null, gaClientId: null,
        ip: '127.0.0.1', userAgent: 'test', idempotencyKey: $key,
        occurredAt: now()->subDays($ageDays)->toDateTimeImmutable(),
    );
}

function expSearches(): int
{
    return Http::recorded(fn ($request) => str_contains($request->url(), 'googleAds:search'))->count();
}

describe('GoogleAdsConversionWindow::isWithinWindow', function (): void {
    it('is eligible inside, exactly at the margin boundary, and not beyond it', function (): void {
        expect(GoogleAdsConversionWindow::isWithinWindow(10, 30, 5))->toBeTrue()
            ->and(GoogleAdsConversionWindow::isWithinWindow(25, 30, 5))->toBeTrue()
            ->and(GoogleAdsConversionWindow::isWithinWindow(25.01, 30, 5))->toBeFalse()
            ->and(GoogleAdsConversionWindow::isWithinWindow(60, 30, 5))->toBeFalse()
            ->and(GoogleAdsConversionWindow::isWithinWindow(0, 3, 5))->toBeTrue();
    });
});

describe('GoogleAdsConversionWindow resolver', function (): void {
    beforeEach(function (): void {
        Cache::flush();
        $this->shop = User::factory()->create();
        $this->integration = expIntegration($this->shop);
        $this->resource = 'customers/1234567890/conversionActions/1';
    });

    it('uses the API value and caches it', function (): void {
        expFakeGoogle(windowDays: 90);
        $window = app(GoogleAdsConversionWindow::class);

        expect($window->windowDays($this->integration, $this->resource))->toBe(90)
            ->and($window->windowDays($this->integration, $this->resource))->toBe(90)
            ->and(expSearches())->toBe(1);
    });

    it('falls back to the configured default when the lookup fails', function (): void {
        expFakeGoogle(searchFails: true);

        expect(app(GoogleAdsConversionWindow::class)->windowDays($this->integration, $this->resource))->toBe(30);
    });

    it('falls back to the default when the field is missing and caches the fallback briefly', function (): void {
        expFakeGoogle(windowDays: null);
        $window = app(GoogleAdsConversionWindow::class);

        expect($window->windowDays($this->integration, $this->resource))->toBe(30)
            ->and($window->windowDays($this->integration, $this->resource))->toBe(30)
            ->and(expSearches())->toBe(1);
    });

    it('survives a cache outage', function (): void {
        expFakeGoogle(windowDays: 90);
        Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'));
        Cache::shouldReceive('put')->andThrow(new RuntimeException('redis down'));

        expect(app(GoogleAdsConversionWindow::class)->windowDays($this->integration, $this->resource))->toBe(90);
    });

    it('flags a fallback result as not from the API', function (): void {
        expFakeGoogle(searchFails: true);
        $window = app(GoogleAdsConversionWindow::class);

        expect($window->lookup($this->integration, $this->resource))->toBe(['days' => 30, 'from_api' => false])
            ->and($window->lookup($this->integration, $this->resource))->toBe(['days' => 30, 'from_api' => false])
            ->and(expSearches())->toBe(1);
    });

    it('does not query Google for an invalid resource name', function (): void {
        expFakeGoogle(windowDays: 90);

        expect(app(GoogleAdsConversionWindow::class)->windowDays($this->integration, "x' OR 1=1"))->toBe(30)
            ->and(expSearches())->toBe(0);
    });
});

describe('live path', function (): void {
    beforeEach(function (): void {
        Cache::flush();
        $this->shop = User::factory()->create();
        $this->integration = expIntegration($this->shop);
    });

    it('stores expired locally and makes no upload for an event outside the window', function (): void {
        expFakeGoogle(windowDays: 30);
        Log::spy();

        app(ProcessTrackingEvent::class)->handle(expData($this->shop, 40));

        $delivery = PlatformDelivery::query()->firstOrFail();
        $body = json_decode((string) $delivery->response_body, true);

        expect($delivery->status)->toBe('expired')
            ->and($delivery->attempts)->toBe(0)
            ->and($body['codes'])->toBe(['EXPIRED_WINDOW_LOCAL'])
            ->and(expUploads())->toBe(0)
            ->and($this->integration->fresh()->last_error)->toBeNull();

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx = []) => $m === 'google_ads.conversion_skipped_outside_window'
            && $ctx['source'] === 'live' && $ctx['window_days'] === 30 && $ctx['safety_margin_days'] === 5
            && $ctx['google_ads_customer_id'] === '1234567890' && ! array_key_exists('gclid', $ctx))->once();
    });

    it('uploads an event exactly at the margin boundary', function (): void {
        expFakeGoogle(windowDays: 30);

        app(ProcessTrackingEvent::class)->handle(expData($this->shop, 25));

        expect(PlatformDelivery::query()->value('status'))->toBe('delivered')
            ->and(expUploads())->toBe(1);
    });

    it('honours a larger window read from Google', function (): void {
        expFakeGoogle(windowDays: 90);

        app(ProcessTrackingEvent::class)->handle(expData($this->shop, 60));

        expect(PlatformDelivery::query()->value('status'))->toBe('delivered');
    });

    it('does not look up the window for same-day events', function (): void {
        expFakeGoogle();

        app(ProcessTrackingEvent::class)->handle(expData($this->shop, 0));

        expect(expSearches())->toBe(0)->and(expUploads())->toBe(1);
    });

    it('records EXPIRED_EVENT from Google as expired, terminal, without incrementing attempts or retrying', function (): void {
        expFakeGoogle('EXPIRED_EVENT');
        Log::spy();

        app(ProcessTrackingEvent::class)->handle(expData($this->shop, 1));

        $delivery = PlatformDelivery::query()->firstOrFail();

        expect($delivery->status)->toBe('expired')
            ->and($delivery->attempts)->toBe(1)
            ->and(json_decode((string) $delivery->response_body, true)['codes'])->toBe(['EXPIRED_EVENT'])
            ->and($this->integration->fresh()->last_error)->toBeNull();

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx = []) => $m === 'google_ads.conversion_expired' && $ctx['source'] === 'google_response')->once();

        app(ProcessTrackingEvent::class)->handle(expData($this->shop, 1));

        expect(expUploads())->toBe(1)
            ->and(PlatformDelivery::query()->count())->toBe(1)
            ->and(PlatformDelivery::query()->value('status'))->toBe('expired')
            ->and(PlatformDelivery::query()->value('attempts'))->toBe(1);
    });

    it('does not re-send a locally expired event on retry', function (): void {
        expFakeGoogle(windowDays: 30);

        app(ProcessTrackingEvent::class)->handle(expData($this->shop, 40));
        app(ProcessTrackingEvent::class)->handle(expData($this->shop, 40));

        expect(expUploads())->toBe(0)->and(PlatformDelivery::query()->count())->toBe(1);
    });

    it('keeps other_account precedence over EXPIRED_EVENT', function (): void {
        expSyncDays($this->integration, 7);
        expFakeGoogle(['INVALID_CUSTOMER_FOR_CLICK', 'EXPIRED_EVENT']);

        app(ProcessTrackingEvent::class)->handle(expData($this->shop, 1));

        expect(PlatformDelivery::query()->value('status'))->toBe('other_account');
    });

    it('still uploads when the window lookup fails', function (): void {
        expFakeGoogle(searchFails: true);

        app(ProcessTrackingEvent::class)->handle(expData($this->shop, 10));

        expect(PlatformDelivery::query()->value('status'))->toBe('delivered');
    });

    it('uploads an old event when the window lookup fails and lets Google decide', function (): void {
        expFakeGoogle(searchFails: true);

        app(ProcessTrackingEvent::class)->handle(expData($this->shop, 40));

        expect(PlatformDelivery::query()->value('status'))->toBe('delivered')
            ->and(expUploads())->toBe(1);
    });

    it('maps Google EXPIRED_EVENT to expired when the window lookup failed', function (): void {
        expFakeGoogle('EXPIRED_EVENT', searchFails: true);

        app(ProcessTrackingEvent::class)->handle(expData($this->shop, 40));

        expect(PlatformDelivery::query()->value('status'))->toBe('expired')
            ->and(expUploads())->toBe(1);
    });

    it('keeps partial_failure when EXPIRED_EVENT is not the primary code', function (array $codes): void {
        expFakeGoogle($codes);

        app(ProcessTrackingEvent::class)->handle(expData($this->shop, 1));

        expect(PlatformDelivery::query()->value('status'))->toBe('partial_failure');
    })->with([
        'real error first' => [['SOME_REAL_ERROR', 'EXPIRED_EVENT']],
        'invalid customer first, no sync data' => [['INVALID_CUSTOMER_FOR_CLICK', 'EXPIRED_EVENT']],
    ]);

    it('does not look up the window again after the upload', function (): void {
        expFakeGoogle('EXPIRED_EVENT', windowDays: 30);

        app(ProcessTrackingEvent::class)->handle(expData($this->shop, 10));

        expect(expSearches())->toBe(1);
    });
});

describe('backfill', function (): void {
    beforeEach(function (): void {
        Cache::flush();
        $this->shop = User::factory()->create();
        $this->integration = expIntegration($this->shop);
    });

    function expEvent(User $shop, int $ageDays, string $gclid): TrackingEvent
    {
        return TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::Purchase)
            ->state(['gclid' => $gclid, 'occurred_at' => now()->subDays($ageDays)])->create();
    }

    it('marks old events expired without an upload and uploads in-window ones', function (): void {
        expFakeGoogle(windowDays: 30);
        $old = expEvent($this->shop, 60, 'gclid-old');
        $fresh = expEvent($this->shop, 3, 'gclid-fresh');
        $delivered = expEvent($this->shop, 60, 'gclid-done');
        PlatformDelivery::query()->create([
            'tracking_event_id' => $delivered->getKey(), 'platform_integration_id' => $this->integration->getKey(),
            'platform' => 'google_ads', 'status' => 'delivered',
        ]);

        $this->artisan('google-ads:backfill', ['integration' => $this->integration->getKey()])->assertSuccessful();

        $statusOf = fn (TrackingEvent $e): string => (string) PlatformDelivery::query()->where('tracking_event_id', $e->getKey())->value('status');

        expect($statusOf($old))->toBe('expired')
            ->and($statusOf($fresh))->toBe('delivered')
            ->and($statusOf($delivered))->toBe('delivered')
            ->and(expUploads())->toBe(1);

        // A second run selects nothing new: expired rows are not re-selected.
        $this->artisan('google-ads:backfill', ['integration' => $this->integration->getKey()])->assertSuccessful();
        expect(expUploads())->toBe(1);
    });

    it('uploads old events when the window lookup fails', function (): void {
        expFakeGoogle(searchFails: true);
        $old = expEvent($this->shop, 60, 'gclid-old');

        $this->artisan('google-ads:backfill', ['integration' => $this->integration->getKey()])->assertSuccessful();

        expect(PlatformDelivery::query()->where('tracking_event_id', $old->getKey())->value('status'))->toBe('delivered')
            ->and(expUploads())->toBe(1);
    });

    it('keeps partial_failure when EXPIRED_EVENT is not the primary code', function (): void {
        expFakeGoogle(['SOME_REAL_ERROR', 'EXPIRED_EVENT']);
        $event = expEvent($this->shop, 3, 'gclid-y');

        $this->artisan('google-ads:backfill', ['integration' => $this->integration->getKey()])->assertSuccessful();

        expect(PlatformDelivery::query()->where('tracking_event_id', $event->getKey())->value('status'))->toBe('partial_failure');
    });

    it('records an EXPIRED_EVENT answer as expired', function (): void {
        expFakeGoogle('EXPIRED_EVENT');
        $event = expEvent($this->shop, 3, 'gclid-x');

        $this->artisan('google-ads:backfill', ['integration' => $this->integration->getKey()])->assertSuccessful();

        expect(PlatformDelivery::query()->where('tracking_event_id', $event->getKey())->value('status'))->toBe('expired');
    });

    it('reports would-upload and would-expire counts on a dry run without writing or uploading', function (): void {
        expFakeGoogle(windowDays: 30);
        expEvent($this->shop, 60, 'gclid-old');
        expEvent($this->shop, 40, 'gclid-old2');
        expEvent($this->shop, 3, 'gclid-fresh');

        $this->artisan('google-ads:backfill', ['integration' => $this->integration->getKey(), '--dry-run' => true])
            ->expectsOutputToContain('would upload 1, would mark expired 2')
            ->assertSuccessful();

        expect(PlatformDelivery::query()->count())->toBe(0)->and(expUploads())->toBe(0);
    });
});

describe('reporting', function (): void {
    it('counts expired separately and keeps failed free of it', function (): void {
        $shop = User::factory()->create();
        $integration = expIntegration($shop);
        $statuses = ['delivered', 'partial_failure', 'expired', 'expired', 'other_account', 'queued'];

        foreach ($statuses as $i => $status) {
            $event = TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::Purchase)
                ->state(['gclid' => 'g'.$i, 'occurred_at' => now()])->create();
            PlatformDelivery::query()->create([
                'tracking_event_id' => $event->getKey(), 'platform_integration_id' => $integration->getKey(),
                'platform' => 'google_ads', 'status' => $status,
                'response_body' => $status === 'expired' ? '{"codes":["EXPIRED_EVENT"],"message":"old"}' : null,
            ]);
        }

        $rows = collect(app(GetPlatformDeliveryStatsForPeriod::class)->handle(
            $shop, CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay(), 'google_ads',
        ))->keyBy('event');
        $purchase = $rows['purchase'];

        expect($purchase['expired'])->toBe(2)
            ->and($purchase['failed'])->toBe(1)
            ->and($purchase['attempted'])->toBe(6)
            ->and($purchase['attempted'])->toBe($purchase['delivered'] + $purchase['failed'] + $purchase['pending'] + $purchase['other_account'] + $purchase['expired'])
            ->and($purchase['last_error'])->toContain('EXPIRED_EVENT');
    });
});

describe('reporting last error', function (): void {
    function expStatsFor(User $shop): array
    {
        return collect(app(GetPlatformDeliveryStatsForPeriod::class)->handle(
            $shop, CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay(), 'google_ads',
        ))->keyBy('event')['purchase'];
    }

    function expRow(User $shop, PlatformIntegration $integration, string $status, string $body, int $n): void
    {
        $event = TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::Purchase)
            ->state(['gclid' => 'le'.$n, 'occurred_at' => now()])->create();
        PlatformDelivery::query()->create([
            'tracking_event_id' => $event->getKey(), 'platform_integration_id' => $integration->getKey(),
            'platform' => 'google_ads', 'status' => $status, 'response_body' => $body,
        ]);
    }

    it('prefers an older real failure over a newer expired row', function (): void {
        $shop = User::factory()->create();
        $integration = expIntegration($shop);
        expRow($shop, $integration, 'partial_failure', '{"codes":["REAL"],"message":"real"}', 1);
        expRow($shop, $integration, 'expired', '{"codes":["EXPIRED_EVENT"],"message":"old"}', 2);

        $row = expStatsFor($shop);

        expect($row['last_error'])->toContain('REAL')->and($row['last_error_status'])->toBe('failed');
    });

    it('falls back to the latest expired row when there is no failure', function (): void {
        $shop = User::factory()->create();
        $integration = expIntegration($shop);
        expRow($shop, $integration, 'expired', '{"codes":["EXPIRED_EVENT"],"message":"old"}', 1);

        $row = expStatsFor($shop);

        expect($row['last_error'])->toContain('EXPIRED_EVENT')->and($row['last_error_status'])->toBe('expired');
    });

    it('has no last error status when there is nothing to show', function (): void {
        $shop = User::factory()->create();
        expIntegration($shop);

        expect(expStatsFor($shop)['last_error_status'])->toBeNull();
    });
});

describe('migration', function (): void {
    it('moves EXPIRED_EVENT partial failures to expired and back', function (): void {
        $shop = User::factory()->create();
        $integration = expIntegration($shop);
        $make = function (string $code) use ($shop, $integration): int {
            $event = TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::Purchase)->state(['gclid' => 'g'.$code])->create();

            return PlatformDelivery::query()->create([
                'tracking_event_id' => $event->getKey(), 'platform_integration_id' => $integration->getKey(),
                'platform' => 'google_ads', 'status' => 'partial_failure',
                'response_body' => json_encode(['codes' => [$code], 'message' => 'm']),
            ])->getKey();
        };
        $expiredId = $make('EXPIRED_EVENT');
        $otherId = $make('CLICK_NOT_FOUND');
        $secondaryEvent = TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::Purchase)->state(['gclid' => 'gsec'])->create();
        $secondaryId = PlatformDelivery::query()->create([
            'tracking_event_id' => $secondaryEvent->getKey(), 'platform_integration_id' => $integration->getKey(),
            'platform' => 'google_ads', 'status' => 'partial_failure',
            'response_body' => json_encode(['codes' => ['SOME_REAL_ERROR', 'EXPIRED_EVENT'], 'message' => 'm']),
        ])->getKey();

        $migration = require database_path('migrations/2026_10_08_000001_reclassify_expired_event_partial_failures.php');
        $migration->up();

        expect(PlatformDelivery::query()->find($expiredId)->status)->toBe('expired')
            ->and(PlatformDelivery::query()->find($otherId)->status)->toBe('partial_failure')
            ->and(PlatformDelivery::query()->find($secondaryId)->status)->toBe('partial_failure');

        $migration->down();

        $restored = PlatformDelivery::query()->find($expiredId);
        expect($restored->status)->toBe('partial_failure')
            ->and(json_decode((string) $restored->response_body, true))->toBe(['codes' => ['EXPIRED_EVENT'], 'message' => 'm']);
    });
});
