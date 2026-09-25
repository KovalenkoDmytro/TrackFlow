<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\PlatformIntegration;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
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
