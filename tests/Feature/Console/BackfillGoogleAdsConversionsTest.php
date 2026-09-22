<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\TrackingEventType;
use App\Models\ConversionActionMapping;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, mixed>  $overrides
 */
function createGoogleAdsIntegration(User $shop, array $overrides = []): PlatformIntegration
{
    return PlatformIntegration::query()->create(array_merge([
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
    ], $overrides));
}

function fakeGoogleAdsOAuthToken(): void
{
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
        'https://googleads.googleapis.com/*' => Http::response([]),
    ]);
}

it('does nothing when the integration is inactive', function (): void {
    Http::fake();

    $shop = User::factory()->create();
    $integration = createGoogleAdsIntegration($shop, ['active' => false]);
    TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::Purchase)->state(['gclid' => 'gclid-1'])->create();

    $this->artisan('google-ads:backfill', ['integration' => $integration->getKey()])
        ->assertFailed();

    expect(PlatformDelivery::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('does nothing when the integration is not a Google Ads integration', function (): void {
    Http::fake();

    $shop = User::factory()->create();
    $integration = createGoogleAdsIntegration($shop, ['platform' => Platform::Meta]);
    TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::Purchase)->state(['gclid' => 'gclid-1'])->create();

    $this->artisan('google-ads:backfill', ['integration' => $integration->getKey()])
        ->assertFailed();

    expect(PlatformDelivery::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('does not create records in dry-run mode', function (): void {
    Http::fake();

    $shop = User::factory()->create();
    $integration = createGoogleAdsIntegration($shop);
    ConversionActionMapping::query()->create([
        'platform_integration_id' => $integration->getKey(),
        'event' => TrackingEventType::Purchase->value,
        'external_action_id' => 'customers/123/conversionActions/1',
        'active' => true,
    ]);
    TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::Purchase)->state(['gclid' => 'gclid-1'])->create();

    $this->artisan('google-ads:backfill', [
        'integration' => $integration->getKey(),
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Eligible events: 1')
        ->assertSuccessful();

    expect(PlatformDelivery::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('creates a successful PlatformDelivery when a mapping is found', function (): void {
    fakeGoogleAdsOAuthToken();

    $shop = User::factory()->create();
    $integration = createGoogleAdsIntegration($shop);
    ConversionActionMapping::query()->create([
        'platform_integration_id' => $integration->getKey(),
        'event' => TrackingEventType::Purchase->value,
        'external_action_id' => 'customers/1234567890/conversionActions/1',
        'active' => true,
    ]);
    $event = TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::Purchase)->state(['gclid' => 'gclid-1'])->create();

    $this->artisan('google-ads:backfill', ['integration' => $integration->getKey()])
        ->assertSuccessful();

    $delivery = PlatformDelivery::query()->where('tracking_event_id', $event->getKey())->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->status)->toBe('delivered')
        ->and($delivery->platform_integration_id)->toBe($integration->getKey())
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->sent_at)->not->toBeNull();
});

it('counts events without an active mapping as no_mapping without creating a delivery', function (): void {
    fakeGoogleAdsOAuthToken();

    $shop = User::factory()->create();
    $integration = createGoogleAdsIntegration($shop);
    $event = TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::Purchase)->state(['gclid' => 'gclid-1'])->create();

    $this->artisan('google-ads:backfill', ['integration' => $integration->getKey()])
        ->expectsOutputToContain('no_mapping')
        ->assertSuccessful();

    expect(PlatformDelivery::query()->where('tracking_event_id', $event->getKey())->exists())->toBeFalse();
});

it('records a failed delivery and does not crash when the upload throws', function (): void {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
        'https://googleads.googleapis.com/v24/customers/1234567890:uploadClickConversions' => Http::response(
            ['error' => ['message' => 'Invalid customer ID.']],
            400,
        ),
    ]);

    $shop = User::factory()->create();
    $integration = createGoogleAdsIntegration($shop);
    ConversionActionMapping::query()->create([
        'platform_integration_id' => $integration->getKey(),
        'event' => TrackingEventType::Purchase->value,
        'external_action_id' => 'customers/1234567890/conversionActions/1',
        'active' => true,
    ]);
    $event = TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::Purchase)->state(['gclid' => 'gclid-1'])->create();

    $this->artisan('google-ads:backfill', ['integration' => $integration->getKey()])
        ->assertFailed();

    $delivery = PlatformDelivery::query()->where('tracking_event_id', $event->getKey())->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->status)->toBe('failed')
        ->and($delivery->response_body)->toContain('Invalid customer ID.');
});
