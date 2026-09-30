<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function ownerIntegration(array $credentials = []): PlatformIntegration
{
    return PlatformIntegration::query()->create([
        'user_id' => User::factory()->create()->getKey(),
        'platform' => Platform::GoogleAds,
        'active' => true,
        'credentials' => json_encode(array_merge([
            'customer_id' => '8132700376',
            'oauth' => ['client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'token'],
        ], $credentials)),
    ]);
}

/** Fake an MCC with two active accounts and one idle one; only account 2222222222 owns the click. */
function fakeMcc(): void
{
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access']),
        'https://googleads.googleapis.com/*' => function ($request) {
            $query = $request['query'];
            if (str_contains($query, 'customer_client')) {
                return Http::response(['results' => [
                    ['customerClient' => ['id' => '1111111111', 'descriptiveName' => 'Other'], 'metrics' => ['clicks' => '5']],
                    ['customerClient' => ['id' => '2222222222', 'descriptiveName' => 'Safe Care Real'], 'metrics' => ['clicks' => '90']],
                    ['customerClient' => ['id' => '3333333333', 'descriptiveName' => 'Idle'], 'metrics' => ['clicks' => '0']],
                ]]);
            }
            $owner = str_contains($request->url(), '/customers/2222222222/');
            $sent = $request->header('login-customer-id')[0] ?? null;

            return Http::response(['results' => $owner && $sent === '9999999999'
                ? [['clickView' => ['gclid' => ' OwnedClick ']]] : []]);
        },
    ]);
}

it('finds the account under the manager that owns the captured click ids', function (): void {
    $integration = ownerIntegration();
    TrackingEvent::factory()->create(['user_id' => $integration->user_id, 'gclid' => 'OwnedClick']);
    TrackingEvent::factory()->create(['user_id' => $integration->user_id, 'gclid' => 'StrayClick']);
    fakeMcc();

    $this->artisan('google-ads:find-click-owner', ['integration' => $integration->getKey(), '--mcc' => '999-999-9999', '--days' => 2])
        ->expectsOutputToContain('2 distinct click IDs to locate.')
        ->expectsOutputToContain('2 account(s) with clicks')
        ->expectsOutputToContain('2222222222')
        ->expectsOutputToContain('1 of 2 click IDs located')
        ->assertSuccessful();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/customers/3333333333/'));
});

it('says so when no account under the manager owns the clicks', function (): void {
    $integration = ownerIntegration(['mcc_id' => '9999999999']);
    TrackingEvent::factory()->create(['user_id' => $integration->user_id, 'gclid' => 'NobodyOwnsThis']);
    fakeMcc();

    $this->artisan('google-ads:find-click-owner', ['integration' => $integration->getKey(), '--days' => 1])
        ->expectsOutputToContain('No account under this manager owns any of these click IDs')
        ->assertSuccessful();
});

it('requires a manager account id', function (): void {
    $integration = ownerIntegration();

    $this->artisan('google-ads:find-click-owner', ['integration' => $integration->getKey()])
        ->expectsOutputToContain('--mcc')
        ->assertFailed();
});

it('does nothing when the shop captured no click ids', function (): void {
    $integration = ownerIntegration(['mcc_id' => '9999999999']);
    Http::fake();

    $this->artisan('google-ads:find-click-owner', ['integration' => $integration->getKey()])
        ->expectsOutputToContain('No click IDs captured')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('rejects an unknown integration and out-of-range days', function (): void {
    $this->artisan('google-ads:find-click-owner', ['integration' => 999])->assertFailed();
    $this->artisan('google-ads:find-click-owner', ['integration' => ownerIntegration()->getKey(), '--days' => 91])->assertFailed();
});
