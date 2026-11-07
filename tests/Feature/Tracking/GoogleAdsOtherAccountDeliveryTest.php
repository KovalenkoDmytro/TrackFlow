<?php

declare(strict_types=1);

use App\Actions\Analytics\GetPlatformDeliveryStatsForPeriod;
use App\Actions\Tracking\ProcessTrackingEvent;
use App\Data\PartialFailure;
use App\Data\TrackingEventData;
use App\Enums\Platform;
use App\Models\ConversionActionMapping;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Services\GoogleAdsOtherAccountClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function oaIntegration(User $shop, string $customerId = '123-456-7890'): PlatformIntegration
{
    $integration = PlatformIntegration::query()->create([
        'user_id' => $shop->getKey(),
        'platform' => Platform::GoogleAds,
        'active' => true,
        'credentials' => json_encode([
            'customer_id' => $customerId,
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

function oaSyncDays(PlatformIntegration $integration, int $days, string $customerId = '1234567890'): void
{
    foreach (range(1, $days) as $d) {
        DB::table('google_ads_click_syncs')->insert([
            'platform_integration_id' => $integration->getKey(), 'customer_id' => $customerId,
            'click_date' => now()->subDays($d)->toDateString(), 'checked_at' => now(),
        ]);
    }
}

function oaSyncedClick(PlatformIntegration $integration, string $gclid, string $customerId = '1234567890'): void
{
    DB::table('google_ads_clicks')->insert([
        'platform_integration_id' => $integration->getKey(), 'customer_id' => $customerId,
        'gclid_hash' => hash('sha256', $gclid), 'click_date' => now()->toDateString(),
        'day_start_utc' => now()->startOfDay(), 'checked_at' => now(),
    ]);
}

function oaData(User $shop, string $gclid, string $key = 'k1'): TrackingEventData
{
    return new TrackingEventData(
        shopDomain: $shop->name, event: 'purchase', value: 10, currency: 'CAD', transactionId: 'T-'.$key,
        gclid: $gclid, fbp: null, fbc: null, ttclid: null, gaClientId: null, ip: '127.0.0.1',
        userAgent: 'test', idempotencyKey: $key, occurredAt: new DateTimeImmutable('now'),
    );
}

function oaFakeGoogle(string $code = 'INVALID_CUSTOMER_FOR_CLICK'): void
{
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
        'https://googleads.googleapis.com/*' => Http::response([
            'partialFailureError' => [
                'code' => 3,
                'message' => 'Errors in request: conversions[0]',
                'details' => [[
                    'errors' => [[
                        'errorCode' => ['conversionUploadError' => $code],
                        'message' => 'The click is associated with a different account.',
                    ]],
                ]],
            ],
        ]),
    ]);
}

function oaUploads(): int
{
    return Http::recorded(fn ($request) => str_contains($request->url(), 'uploadClickConversions'))->count();
}

describe('Google Ads other_account classification', function (): void {
    beforeEach(function (): void {
        $this->shop = User::factory()->create();
        $this->integration = oaIntegration($this->shop);
    });

    it('stores other_account, keeps the failure reason and customer id, and leaves last_error untouched', function (): void {
        oaSyncDays($this->integration, 7);
        oaFakeGoogle();
        Log::spy();

        app(ProcessTrackingEvent::class)->handle(oaData($this->shop, 'gclid-foreign'));

        $delivery = PlatformDelivery::query()->firstOrFail();
        $body = json_decode((string) $delivery->response_body, true);

        expect($delivery->status)->toBe('other_account')
            ->and($delivery->attempts)->toBe(1)
            ->and($body['codes'])->toBe(['INVALID_CUSTOMER_FOR_CLICK'])
            ->and($body['message'])->toContain('different account')
            ->and($body['customer_id'])->toBe('1234567890')
            ->and($this->integration->fresh()->last_error)->toBeNull()
            ->and($this->integration->fresh()->last_error_at)->toBeNull();

        Log::shouldNotHaveReceived('warning');
        Log::shouldHaveReceived('info')->withArgs(fn (string $m) => str_contains($m, 'another Google Ads account'))->once();
    });

    it('keeps partial_failure with last_error when the click is in the synced clicks of the account', function (): void {
        oaSyncDays($this->integration, 7);
        oaSyncedClick($this->integration, 'gclid-mine');
        oaFakeGoogle();

        app(ProcessTrackingEvent::class)->handle(oaData($this->shop, 'gclid-mine'));

        expect(PlatformDelivery::query()->value('status'))->toBe('partial_failure')
            ->and($this->integration->fresh()->last_error)->toBe('partial_failure: INVALID_CUSTOMER_FOR_CLICK');
    });

    it('keeps partial_failure when the click sync does not cover enough days', function (): void {
        oaSyncDays($this->integration, 6);
        oaSyncDays($this->integration, 30, '9999999999'); // another customer id is no evidence
        oaFakeGoogle();

        app(ProcessTrackingEvent::class)->handle(oaData($this->shop, 'gclid-foreign'));

        expect(PlatformDelivery::query()->value('status'))->toBe('partial_failure')
            ->and($this->integration->fresh()->last_error)->toBe('partial_failure: INVALID_CUSTOMER_FOR_CLICK');
    });

    it('does not classify other rejection codes as other_account', function (): void {
        oaSyncDays($this->integration, 7);
        oaFakeGoogle('INVALID_CONVERSION_ACTION');

        app(ProcessTrackingEvent::class)->handle(oaData($this->shop, 'gclid-foreign'));

        expect(PlatformDelivery::query()->value('status'))->toBe('partial_failure')
            ->and($this->integration->fresh()->last_error)->toBe('partial_failure: INVALID_CONVERSION_ACTION');
    });

    it('does not re-upload an other_account event on retry', function (): void {
        oaSyncDays($this->integration, 7);
        oaFakeGoogle();

        app(ProcessTrackingEvent::class)->handle(oaData($this->shop, 'gclid-foreign'));
        app(ProcessTrackingEvent::class)->handle(oaData($this->shop, 'gclid-foreign'));

        expect(oaUploads())->toBe(1)
            ->and(PlatformDelivery::query()->count())->toBe(1)
            ->and(PlatformDelivery::query()->value('status'))->toBe('other_account');
    });

    it('skips the upload for a later event with a known other-account gclid and the same customer id', function (): void {
        oaSyncDays($this->integration, 7);
        oaFakeGoogle();

        app(ProcessTrackingEvent::class)->handle(oaData($this->shop, 'gclid-foreign', 'k1'));
        expect(oaUploads())->toBe(1);

        app(ProcessTrackingEvent::class)->handle(oaData($this->shop, 'gclid-foreign', 'k2'));

        $second = PlatformDelivery::query()->latest('id')->firstOrFail();
        $body = json_decode((string) $second->response_body, true);

        expect(oaUploads())->toBe(1)
            ->and($second->status)->toBe('other_account')
            ->and($second->attempts)->toBe(0)
            ->and($body['skipped'])->toBeTrue()
            ->and($body['customer_id'])->toBe('1234567890')
            ->and($this->integration->fresh()->last_error)->toBeNull();
    });

    it('uploads again when the integration now points at a different customer id', function (): void {
        oaSyncDays($this->integration, 7);
        oaFakeGoogle();

        app(ProcessTrackingEvent::class)->handle(oaData($this->shop, 'gclid-foreign', 'k1'));

        $creds = json_decode((string) $this->integration->credentials, true);
        $creds['customer_id'] = '999-888-7777';
        $this->integration->update(['credentials' => json_encode($creds)]);

        app(ProcessTrackingEvent::class)->handle(oaData($this->shop, 'gclid-foreign', 'k2'));

        // No sync coverage for the new customer id, so the second rejection stays a partial failure.
        expect(oaUploads())->toBe(2)
            ->and(PlatformDelivery::query()->latest('id')->value('status'))->toBe('partial_failure');
    });

    it('always uploads the first event for a gclid', function (): void {
        oaSyncDays($this->integration, 7);
        oaFakeGoogle();

        app(ProcessTrackingEvent::class)->handle(oaData($this->shop, 'gclid-new'));

        expect(oaUploads())->toBe(1);
    });

    it('does not let another shop or integration short-circuit an upload', function (): void {
        $otherShop = User::factory()->create();
        $other = oaIntegration($otherShop);
        $event = TrackingEvent::factory()->forUser($otherShop)->create(['gclid' => 'g', 'gclid_hash' => hash('sha256', 'g')]);
        PlatformDelivery::query()->create([
            'tracking_event_id' => $event->getKey(), 'platform_integration_id' => $other->getKey(),
            'platform' => 'google_ads', 'status' => 'other_account', 'attempts' => 1,
            'response_body' => json_encode(['codes' => ['INVALID_CUSTOMER_FOR_CLICK'], 'customer_id' => '1234567890']),
        ]);
        oaFakeGoogle();

        app(ProcessTrackingEvent::class)->handle(oaData($this->shop, 'g'));

        expect(oaUploads())->toBe(1);
    });
});

describe('other_account delivery stats', function (): void {
    it('counts other_account separately, outside failed and last_error, while attempted counts all rows', function (): void {
        $shop = User::factory()->create();
        $integration = oaIntegration($shop);
        $make = function (string $status, ?string $body = null) use ($shop, $integration): void {
            $event = TrackingEvent::factory()->forUser($shop)->create(['event' => 'purchase', 'occurred_at' => now()]);
            PlatformDelivery::query()->create([
                'tracking_event_id' => $event->getKey(), 'platform_integration_id' => $integration->getKey(),
                'platform' => 'google_ads', 'status' => $status, 'attempts' => 1, 'response_body' => $body,
            ]);
        };
        $make('delivered');
        $make('partial_failure', 'real problem');
        $make('other_account', '{"codes":["INVALID_CUSTOMER_FOR_CLICK"],"customer_id":"1234567890"}');
        $make('other_account', '{"codes":["INVALID_CUSTOMER_FOR_CLICK"],"customer_id":"1234567890"}');

        $stats = app(GetPlatformDeliveryStatsForPeriod::class);
        $start = CarbonImmutable::now()->startOfDay();
        $end = CarbonImmutable::now()->endOfDay();
        $purchase = collect($stats->handle($shop, $start, $end, 'google_ads'))->firstWhere('event', 'purchase');

        expect($purchase)->toMatchArray(['attempted' => 4, 'delivered' => 1, 'failed' => 1, 'pending' => 0, 'other_account' => 2, 'last_error' => 'real problem'])
            ->and($stats->otherAccountShare($shop, $start, $end, 'google_ads'))->toBe(['attempted' => 4, 'other_account' => 2]);
    });
});

describe('Meta and GA4 are unchanged', function (): void {
    it('never classifies a Meta partial failure as other_account', function (): void {
        $shop = User::factory()->create(['tracking_secret' => 's']);
        $integration = PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(), 'platform' => Platform::Meta, 'active' => true,
            'credentials' => json_encode(['pixel_id' => '1', 'access_token' => 't']), 'settings' => [],
        ]);
        ConversionActionMapping::query()->create([
            'platform_integration_id' => $integration->getKey(), 'event' => 'purchase',
            'external_action_id' => 'Purchase', 'active' => true,
        ]);
        Http::fake(['https://graph.facebook.com/*' => Http::response(['events_received' => 0], 200)]);
        oaSyncDays($integration, 30); // even with "coverage" the Meta path must not classify

        app(ProcessTrackingEvent::class)->handle(oaData($shop, 'gclid-x'));

        expect(PlatformDelivery::query()->value('status'))->toBe('partial_failure')
            ->and($integration->fresh()->last_error)->toBe('partial_failure: meta_no_events_received');
    });
});

describe('GoogleAdsOtherAccountClassifier', function (): void {
    it('requires INVALID_CUSTOMER_FOR_CLICK, coverage and an unmatched click', function (): void {
        $shop = User::factory()->create();
        $integration = oaIntegration($shop);
        $event = TrackingEvent::factory()->forUser($shop)->create(['gclid' => ' abc ', 'gclid_hash' => null]);
        $classifier = app(GoogleAdsOtherAccountClassifier::class);
        $failure = new PartialFailure(['INVALID_CUSTOMER_FOR_CLICK'], 'm');

        expect($classifier->isOtherAccountFailure($integration, $event, $failure))->toBeFalse(); // no coverage

        oaSyncDays($integration, 7);
        expect($classifier->isOtherAccountFailure($integration, $event, $failure))->toBeTrue()
            ->and($classifier->isOtherAccountFailure($integration, $event, new PartialFailure(['EXPIRED_EVENT'], 'm')))->toBeFalse()
            ->and($classifier->isOtherAccountFailure($integration, $event, null))->toBeFalse();

        oaSyncedClick($integration, 'abc'); // hash of the trimmed gclid
        expect($classifier->isOtherAccountFailure($integration, $event, $failure))->toBeFalse();
    });
});
