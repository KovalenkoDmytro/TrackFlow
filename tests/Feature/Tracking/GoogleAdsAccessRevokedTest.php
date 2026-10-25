<?php

declare(strict_types=1);

use App\Actions\GoogleAds\MarkGoogleAdsAccessRevoked;
use App\Actions\Tracking\ProcessTrackingEvent;
use App\Data\TrackingEventData;
use App\Enums\Platform;
use App\Exceptions\GoogleAds\GoogleAdsAccessRevoked;
use App\Models\ConversionActionMapping;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\User;
use App\Services\GoogleAdsClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

function revokedIntegration(User $shop, ?string $mcc = null): PlatformIntegration
{
    $integration = PlatformIntegration::query()->create([
        'user_id' => $shop->getKey(),
        'platform' => Platform::GoogleAds,
        'active' => true,
        'credentials' => json_encode([
            'customer_id' => '1234567890',
            'mcc_id' => $mcc,
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

/** @return array<string, mixed> */
function permissionDeniedBody(string $code = 'USER_PERMISSION_DENIED'): array
{
    return ['error' => [
        'code' => 403,
        'message' => 'The caller does not have permission',
        'status' => 'PERMISSION_DENIED',
        'details' => [['errors' => [['errorCode' => ['authorizationError' => $code], 'message' => 'x']]]],
    ]];
}

function fakeRevokedGoogle(string $code = 'USER_PERMISSION_DENIED'): void
{
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
        'https://googleads.googleapis.com/*' => Http::response(permissionDeniedBody($code), 403),
    ]);
}

beforeEach(function (): void {
    Cache::flush();
});

describe('click sync', function (): void {
    it('deactivates the integration, logs a warning and exits successfully on USER_PERMISSION_DENIED', function (): void {
        $integration = revokedIntegration(User::factory()->create());
        fakeRevokedGoogle();

        Log::spy();

        $this->artisan('google-ads:sync-clicks', ['--integration' => $integration->getKey()])
            ->expectsOutputToContain('access revoked')
            ->assertSuccessful();

        Log::shouldHaveReceived('warning')->once()
            ->withArgs(fn (string $m, array $c): bool => str_contains($m, 'access revoked') && $c['integration'] === $integration->getKey());
        Log::shouldNotHaveReceived('error');

        $integration->refresh();
        expect($integration->active)->toBeFalse()
            ->and($integration->last_error)->toBe(MarkGoogleAdsAccessRevoked::LAST_ERROR_NO_MCC)
            ->and($integration->last_error_at)->not->toBeNull();
    });

    it('does not call Google again for a deactivated integration on the next run', function (): void {
        $integration = revokedIntegration(User::factory()->create());
        fakeRevokedGoogle();

        $this->artisan('google-ads:sync-clicks')->assertSuccessful();
        Http::fake();
        $this->artisan('google-ads:sync-clicks')->assertSuccessful();

        Http::assertNothingSent();
    });

    it('keeps failing loudly for a 403 that is our own misconfiguration', function (string $code): void {
        $integration = revokedIntegration(User::factory()->create());
        fakeRevokedGoogle($code);

        $this->artisan('google-ads:sync-clicks', ['--integration' => $integration->getKey()])
            ->expectsOutputToContain('click matching failed')
            ->assertFailed();

        expect($integration->refresh()->active)->toBeTrue();
    })->with(['CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION', 'ACTION_NOT_PERMITTED', 'DEVELOPER_TOKEN_NOT_APPROVED']);

    it('mentions the missing manager account only when no mcc_id is stored', function (): void {
        $integration = revokedIntegration(User::factory()->create(), '9876543210');
        fakeRevokedGoogle();

        $this->artisan('google-ads:sync-clicks')->assertSuccessful();

        expect($integration->refresh()->last_error)->toBe(MarkGoogleAdsAccessRevoked::LAST_ERROR);
    });
});

describe('health:check', function (): void {
    it('neither flags nor mails an integration that was deactivated by a revoked access', function (): void {
        Mail::fake();
        config()->set('alerts.email', 'owner@example.com');
        $integration = revokedIntegration(User::factory()->create());
        fakeRevokedGoogle();

        // Before the first sync failure the integration is stale and flagged.
        $this->artisan('health:check --no-mail')->assertFailed();

        $this->artisan('google-ads:sync-clicks')->assertSuccessful();

        $this->artisan('health:check')->assertSuccessful();
        Mail::assertNothingSent();
        expect($integration->refresh()->active)->toBeFalse();
    });
});

describe('conversion upload', function (): void {
    function revokedEventData(User $shop): TrackingEventData
    {
        return new TrackingEventData(
            shopDomain: $shop->name, event: 'purchase', value: 10, currency: 'CAD', transactionId: 'T-1',
            gclid: 'gclid-1', fbp: null, fbc: null, ttclid: null, gaClientId: null, ip: '127.0.0.1',
            userAgent: 'test', idempotencyKey: 'k-1', occurredAt: new DateTimeImmutable('now'),
        );
    }

    it('throws the dedicated exception from the client', function (): void {
        fakeRevokedGoogle();

        expect(fn () => app(GoogleAdsClient::class)->uploadClickConversion(
            ['customer_id' => '1234567890', 'oauth' => ['client_id' => 'c', 'client_secret' => 's', 'refresh_token' => 'r']],
            'customers/1234567890/conversionActions/1',
            revokedEventData(User::factory()->create()),
        ))->toThrow(GoogleAdsAccessRevoked::class);
    });

    it('deactivates the integration without retrying, leaving no delivery row to block a later backfill', function (): void {
        $shop = User::factory()->create();
        $integration = revokedIntegration($shop);
        DB::table('google_ads_click_syncs')->insert([
            'platform_integration_id' => $integration->getKey(), 'customer_id' => '1234567890',
            'click_date' => now()->toDateString(), 'checked_at' => now(),
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            '*googleAds:search' => Http::response(['results' => []]),
            '*uploadClickConversions' => Http::response(permissionDeniedBody(), 403),
        ]);

        // Does not throw: the queue must not retry and Sentry must not see an exception.
        ProcessTrackingEvent::run(revokedEventData($shop));

        expect($integration->refresh()->active)->toBeFalse()
            ->and(PlatformDelivery::query()->count())->toBe(0);

        // The next event is not sent to Google at all.
        Http::fake();
        ProcessTrackingEvent::run(revokedEventData($shop));
        Http::assertNothingSent();
    });
});

describe('reconnect', function (): void {
    it('clears the revoked state and sync resumes', function (): void {
        $shop = User::factory()->create();
        Queue::fake();
        $integration = revokedIntegration($shop);
        $accessRestored = false;
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            'https://googleads.googleapis.com/*' => function ($request) use (&$accessRestored) {
                if (! $accessRestored) {
                    return Http::response(permissionDeniedBody(), 403);
                }

                return str_contains((string) $request['query'], 'customer.time_zone')
                    ? Http::response(['results' => [['customer' => ['timeZone' => 'UTC']]]])
                    : Http::response(['results' => []]);
            },
        ]);
        $this->artisan('google-ads:sync-clicks')->assertSuccessful();
        expect($integration->refresh()->active)->toBeFalse();

        $accessRestored = true;

        $this->withToken($this->shopifySessionToken($shop))->postJson('/api/settings/google-ads', [
            'customer_id' => '123-456-7890',
            'oauth_client_id' => 'c',
            'oauth_client_secret' => 's',
            'oauth_refresh_token' => 'r',
        ])->assertOk();

        $integration->refresh();
        expect($integration->active)->toBeTrue()
            ->and($integration->last_error)->toBeNull()
            ->and($integration->last_error_at)->toBeNull();

        $this->artisan('google-ads:sync-clicks', ['--integration' => $integration->getKey(), '--days' => 1])
            ->expectsOutputToContain('click reports updated')
            ->assertSuccessful();
    });

    it('cannot reconnect while Google still denies access', function (): void {
        $shop = User::factory()->create();
        $integration = revokedIntegration($shop);
        fakeRevokedGoogle();
        $this->artisan('google-ads:sync-clicks')->assertSuccessful();

        $this->withToken($this->shopifySessionToken($shop))->postJson('/api/settings/google-ads', [
            'customer_id' => '123-456-7890',
            'oauth_client_id' => 'c',
            'oauth_client_secret' => 's',
            'oauth_refresh_token' => 'r',
        ])->assertStatus(422);

        expect($integration->refresh()->active)->toBeFalse();
    });
});
