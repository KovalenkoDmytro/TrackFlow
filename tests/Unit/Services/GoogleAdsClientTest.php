<?php

declare(strict_types=1);

use App\Data\TrackingEventData;
use App\Enums\Platform;
use App\Models\ConversionActionMapping;
use App\Models\PlatformIntegration;
use App\Models\User;
use App\Services\GoogleAdsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('repairs mappings from existing Google Ads conversion actions without creating duplicates', function (): void {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
        'https://googleads.googleapis.com/v24/customers/1234567890/googleAds:search' => Http::response([
            'results' => [
                ['conversionAction' => [
                    'resourceName' => 'customers/1234567890/conversionActions/100',
                    'name' => 'TF - purchase',
                ]],
            ],
        ]),
        'https://googleads.googleapis.com/v24/customers/1234567890/conversionActions:mutate' => function ($request) {
            $name = $request->data()['operations'][0]['create']['name'];
            $event = substr($name, strlen('TF - '));

            return Http::response([
                'results' => [[
                    'resourceName' => "customers/1234567890/conversionActions/new-{$event}",
                ]],
            ]);
        },
    ]);

    $shop = User::factory()->create();
    $integration = PlatformIntegration::query()->create([
        'user_id' => $shop->getKey(),
        'platform' => Platform::GoogleAds,
        'active' => true,
        'credentials' => json_encode([
            'customer_id' => '123-456-7890',
            'developer_token' => 'developer-token',
            'oauth' => [
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'refresh_token' => 'refresh-token',
            ],
        ]),
    ]);

    (new GoogleAdsClient)->setupConversionActions($integration);

    expect(ConversionActionMapping::query()->where('platform_integration_id', $integration->getKey())->count())
        ->toBe(9)
        ->and(ConversionActionMapping::query()
            ->where('platform_integration_id', $integration->getKey())
            ->where('event', 'purchase')
            ->value('external_action_id'))
        ->toBe('customers/1234567890/conversionActions/100');

    Http::assertNotSent(fn ($request) => $request->hasHeader('developer-token'));
    Http::assertSentCount(18); // List OAuth/search + OAuth/mutate for each of 8 missing actions.
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/conversionActions:mutate')
        && $request->data()['operations'][0]['create']['name'] === 'TF - purchase');
});

it('uses OAuth and the optional manager ID without a developer token', function (): void {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
        'https://googleads.googleapis.com/*' => Http::response(['results' => []]),
    ]);

    (new GoogleAdsClient)->testCredentials([
        'customer_id' => '123-456-7890',
        'mcc_id' => '987-654-3210',
        'oauth' => ['client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'refresh'],
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/customers/1234567890/')
        && $request->hasHeader('Authorization', 'Bearer access-token')
        && $request->hasHeader('login-customer-id', '9876543210')
        && ! $request->hasHeader('developer-token'));
});

it('explains Google Cloud access errors from nested Google Ads details', function (string $reason): void {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
        'https://googleads.googleapis.com/*' => Http::response([
            'error' => [
                'message' => 'The caller does not have permission',
                'details' => [['errors' => [['errorCode' => ['authorizationError' => $reason]]]]],
            ],
        ], 403),
    ]);

    expect(fn () => (new GoogleAdsClient)->testCredentials([
        'customer_id' => '1234567890',
        'oauth' => ['client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'refresh'],
    ]))->toThrow(RuntimeException::class, 'Explorer access');
})->with(['CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION', 'ACTION_NOT_PERMITTED']);

function clampTestEvent(DateTimeImmutable $occurredAt): TrackingEventData
{
    return new TrackingEventData(
        shopDomain: 'shop.myshopify.com',
        event: 'purchase',
        value: 10.0,
        currency: 'USD',
        transactionId: 'order-1',
        gclid: 'SECRET-GCLID-123',
        fbp: null,
        fbc: null,
        ttclid: null,
        gaClientId: null,
        ip: null,
        userAgent: null,
        idempotencyKey: 'key-1',
        occurredAt: $occurredAt,
    );
}

function uploadAndGetConversionTime(DateTimeImmutable $occurredAt): string
{
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
        'https://googleads.googleapis.com/*' => Http::response(['results' => [[]]]),
    ]);

    (new GoogleAdsClient)->uploadClickConversion(
        ['customer_id' => '123-456-7890', 'oauth' => ['client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'refresh']],
        'customers/1234567890/conversionActions/1',
        clampTestEvent($occurredAt),
    );

    $sent = null;
    Http::assertSent(function ($request) use (&$sent) {
        if (str_contains($request->url(), 'uploadClickConversions')) {
            $sent = $request->data()['conversions'][0]['conversion_date_time'];
        }

        return true;
    });

    return $sent;
}

describe('conversion time clamp', function (): void {
    beforeEach(function (): void {
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
    });

    afterEach(fn () => Carbon::setTestNow());

    it('clamps a future event time to now minus 60 seconds in UTC', function (): void {
        Log::spy();

        $sent = uploadAndGetConversionTime(new DateTimeImmutable('2026-10-08 12:02:00+00:00'));

        expect($sent)->toBe('2026-10-08 11:59:00+00:00');

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'google_ads.conversion_time_clamped'
                && $context['skew_seconds'] === 120
                && $context['google_ads_customer_id'] === '1234567890'
                && ! str_contains(json_encode($context), 'SECRET-GCLID');
        });
    });

    it('clamps an event time inside the safety margin silently', function (): void {
        Log::spy();

        expect(uploadAndGetConversionTime(new DateTimeImmutable('2026-10-08 11:59:30+00:00')))
            ->toBe('2026-10-08 11:59:00+00:00');

        Log::shouldNotHaveReceived('warning');
    });

    it('leaves a past event time unchanged and does not log', function (): void {
        Log::spy();

        expect(uploadAndGetConversionTime(new DateTimeImmutable('2026-10-08 11:50:00+00:00')))
            ->toBe('2026-10-08 11:50:00+00:00');

        Log::shouldNotHaveReceived('warning');
    });

    it('leaves an event time exactly at the boundary unchanged and does not log', function (): void {
        Log::spy();

        expect(uploadAndGetConversionTime(new DateTimeImmutable('2026-10-08 11:59:00+00:00')))
            ->toBe('2026-10-08 11:59:00+00:00');

        Log::shouldNotHaveReceived('warning');
    });

    it('converts a non-UTC offset to the correct UTC instant', function (): void {
        // 07:50 at -05:00 is 12:50 UTC (future) -> clamped; 05:50 at -05:00 is 10:50 UTC (past) -> kept.
        expect(uploadAndGetConversionTime(new DateTimeImmutable('2026-10-08 07:50:00-05:00')))
            ->toBe('2026-10-08 11:59:00+00:00');
        expect(uploadAndGetConversionTime(new DateTimeImmutable('2026-10-08 05:50:00-05:00')))
            ->toBe('2026-10-08 10:50:00+00:00');
    });
});
