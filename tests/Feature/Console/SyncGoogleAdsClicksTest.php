<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\PlatformIntegration;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * @param  array<string, mixed>  $overrides
 */
function createSyncableGoogleAdsIntegration(User $shop, array $overrides = []): PlatformIntegration
{
    return PlatformIntegration::query()->create(array_merge([
        'user_id' => $shop->getKey(),
        'platform' => Platform::GoogleAds,
        'active' => true,
        'credentials' => json_encode(['customer_id' => '1234567890']),
    ], $overrides));
}

it('reports a lock clash as a skip and still exits successfully', function (): void {
    $shop = User::factory()->create();
    $integration = createSyncableGoogleAdsIntegration($shop);

    // Simulate the hourly/daily schedules overlapping: hold the same
    // per-integration lock GoogleAdsClickSync::sync() acquires.
    $lock = Cache::lock('google-ads-click-sync:'.$integration->getKey(), 7200);
    expect($lock->get())->toBeTrue();

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'sync already running for integration')
            && str_contains($message, (string) $integration->getKey()));

    try {
        $this->artisan('google-ads:sync-clicks', ['--integration' => $integration->getKey()])
            ->expectsOutputToContain('sync already running, skipping.')
            ->assertSuccessful();
    } finally {
        $lock->release();
    }
});

it('does not report a lock clash on one integration as a failure for the whole run', function (): void {
    $shopA = User::factory()->create();
    $shopB = User::factory()->create();
    $lockedIntegration = createSyncableGoogleAdsIntegration($shopA);
    // Malformed credentials on the other integration guarantee sync() throws
    // before any HTTP call — a genuine failure, not a lock clash.
    $failingIntegration = createSyncableGoogleAdsIntegration($shopB, ['credentials' => 'not-valid-json']);

    $lock = Cache::lock('google-ads-click-sync:'.$lockedIntegration->getKey(), 7200);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('google-ads:sync-clicks')
            ->expectsOutputToContain('sync already running, skipping.')
            ->expectsOutputToContain('click matching failed')
            ->assertFailed();
    } finally {
        $lock->release();
    }
});

it('reports a genuine sync failure (not a lock clash) as a command failure', function (): void {
    $shop = User::factory()->create();
    $integration = createSyncableGoogleAdsIntegration($shop, ['credentials' => 'not-valid-json']);

    $this->artisan('google-ads:sync-clicks', ['--integration' => $integration->getKey()])
        ->expectsOutputToContain('click matching failed')
        ->assertFailed();
});

it('logs the Google status and reason when the click report fails', function (): void {
    $shop = User::factory()->create();
    $integration = createSyncableGoogleAdsIntegration($shop, ['credentials' => json_encode([
        'customer_id' => '1234567890',
        'oauth' => ['client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'token'],
    ])]);
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'super-secret-access']),
        'https://googleads.googleapis.com/*' => Http::response(
            ['error' => ['message' => 'The caller does not have permission']], 403),
    ]);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'google-ads:sync-clicks failed'
            && $context['integration'] === $integration->getKey()
            && str_contains($context['message'], '[403]')
            && str_contains($context['message'], 'The caller does not have permission')
            && ! str_contains($context['message'], 'super-secret-access'));

    $this->artisan('google-ads:sync-clicks', ['--integration' => $integration->getKey()])
        ->expectsOutputToContain('click matching failed')
        ->assertFailed();
});

it('matches a click whose gclid has surrounding whitespace', function (): void {
    $shop = User::factory()->create();
    $integration = createSyncableGoogleAdsIntegration($shop, ['credentials' => json_encode([
        'customer_id' => '1234567890',
        'oauth' => ['client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'token'],
    ])]);
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access']),
        'https://googleads.googleapis.com/*' => fn ($request) => str_contains($request['query'], 'customer.time_zone')
            ? Http::response(['results' => [['customer' => ['timeZone' => 'UTC']]]])
            : Http::response(['results' => [['clickView' => ['gclid' => "  AbC123 \n"]]]]),
    ]);

    $this->artisan('google-ads:sync-clicks', ['--integration' => $integration->getKey(), '--days' => 1])
        ->assertSuccessful();

    expect(DB::table('google_ads_clicks')->where('gclid_hash', hash('sha256', 'AbC123'))->exists())->toBeTrue();
});
