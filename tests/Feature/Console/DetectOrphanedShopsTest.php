<?php

declare(strict_types=1);

use App\Actions\Shopify\MigrateOfflineToken;
use App\Models\User;
use Gnikyt\BasicShopifyAPI\ResponseAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;

function orphanedShop(?Carbon $orphanedAt, bool $migrated = false): User
{
    return User::factory()->create([
        'password' => 'legacy-offline-token',
        'orphaned_at' => $orphanedAt,
        'shopify_offline_access_token_expires_at' => $migrated ? Carbon::now()->addDay() : null,
    ]);
}

describe('shopify:detect-orphaned-shops', function (): void {
    it('alerts on shops orphaned beyond the grace period', function (): void {
        Log::shouldReceive('critical')->once()->withArgs(
            fn (string $tag) => $tag === 'shopify.offline_token_migration.orphan_alert',
        );

        $stale = orphanedShop(Carbon::now()->subDays(5));

        $this->artisan('shopify:detect-orphaned-shops')
            ->expectsOutputToContain((string) $stale->getKey())
            ->assertSuccessful();
    });

    it('ignores shops orphaned within the grace period', function (): void {
        Log::shouldReceive('critical')->never();

        orphanedShop(Carbon::now()->subDay());

        $this->artisan('shopify:detect-orphaned-shops')
            ->expectsOutputToContain('No orphaned shops beyond the grace period.')
            ->assertSuccessful();
    });

    it('ignores shops that have already been migrated even if orphaned_at is stale', function (): void {
        Log::shouldReceive('critical')->never();

        orphanedShop(Carbon::now()->subDays(10), migrated: true);

        $this->artisan('shopify:detect-orphaned-shops')
            ->expectsOutputToContain('No orphaned shops beyond the grace period.')
            ->assertSuccessful();
    });

    it('ignores shops that were never orphaned', function (): void {
        Log::shouldReceive('critical')->never();

        orphanedShop(null);

        $this->artisan('shopify:detect-orphaned-shops')
            ->expectsOutputToContain('No orphaned shops beyond the grace period.')
            ->assertSuccessful();
    });

    it('no longer flags a shop once MigrateOfflineToken successfully re-migrates it and clears orphaned_at', function (): void {
        // Shop was orphaned well beyond the grace period, then someone manually
        // re-authenticated it (or a retried run finally succeeded).
        $shop = orphanedShop(Carbon::now()->subDays(10));

        $apiHelper = Mockery::mock(IApiHelper::class);
        $apiHelper->shouldReceive('exchangeNonExpiringOfflineTokenForExpiring')
            ->once()
            ->with($shop->name, 'legacy-offline-token')
            ->andReturn(new ResponseAccess([
                'access_token' => 'new-expiring-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 31536000,
            ]));
        $this->app->instance(IApiHelper::class, $apiHelper);

        // Uses the real IShopCommand implementation (not mocked) so that
        // shopify_offline_access_token_expires_at is actually persisted —
        // required for DetectOrphanedShops' WHERE clause to correctly exclude this shop.
        MigrateOfflineToken::run($shop);

        $shop->refresh();
        expect($shop->orphaned_at)->toBeNull()
            ->and($shop->shopify_offline_access_token_expires_at)->not->toBeNull();

        Log::shouldReceive('critical')->never();

        $this->artisan('shopify:detect-orphaned-shops')
            ->expectsOutputToContain('No orphaned shops beyond the grace period.')
            ->assertSuccessful();
    });
});
