<?php

declare(strict_types=1);

use App\Models\User;
use Gnikyt\BasicShopifyAPI\BasicShopifyAPI;
use Gnikyt\BasicShopifyAPI\ResponseAccess;
use Illuminate\Support\Carbon;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Contracts\Commands\Shop as IShopCommand;
use Osiset\ShopifyApp\Exceptions\ApiException;

function legacyOfflineShop(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'password' => 'legacy-offline-token',
        'shopify_offline_access_token_expires_at' => null,
        'shopify_offline_refresh_token' => null,
        'shopify_offline_refresh_token_expires_at' => null,
        'orphaned_at' => null,
    ], $attributes));
}

describe('shopify:migrate-offline-tokens --dry-run', function (): void {
    it('lists affected shops and makes no API calls or DB writes', function (): void {
        legacyOfflineShop();
        legacyOfflineShop();
        legacyOfflineShop(['shopify_offline_access_token_expires_at' => Carbon::now()->addDay()]);

        $apiHelper = Mockery::mock(IApiHelper::class);
        $apiHelper->shouldNotReceive('exchangeNonExpiringOfflineTokenForExpiring');
        $apiHelper->shouldNotReceive('make');
        $this->app->instance(IApiHelper::class, $apiHelper);

        $shopCommand = Mockery::mock(IShopCommand::class);
        $shopCommand->shouldNotReceive('setAccessToken');
        $this->app->instance(IShopCommand::class, $shopCommand);

        $this->artisan('shopify:migrate-offline-tokens', ['--dry-run' => true])
            ->expectsOutputToContain('Found 2 shop(s)')
            ->expectsOutputToContain('Dry run complete')
            ->assertSuccessful();

        expect(User::query()->whereNull('shopify_offline_access_token_expires_at')->count())->toBe(2);
    });
});

describe('shopify:migrate-offline-tokens --dry-run --shop=', function (): void {
    it('shows a what-if preview for only the targeted shop and makes no API calls or DB writes', function (): void {
        $target = legacyOfflineShop();
        legacyOfflineShop();
        legacyOfflineShop();

        $apiHelper = Mockery::mock(IApiHelper::class);
        $apiHelper->shouldNotReceive('exchangeNonExpiringOfflineTokenForExpiring');
        $apiHelper->shouldNotReceive('make');
        $this->app->instance(IApiHelper::class, $apiHelper);

        $shopCommand = Mockery::mock(IShopCommand::class);
        $shopCommand->shouldNotReceive('setAccessToken');
        $this->app->instance(IShopCommand::class, $shopCommand);

        // Note: intentionally not also asserting on (string) $target->getKey() here.
        // Laravel's expectsOutputToContain() registers one Mockery expectation per
        // substring, and Mockery dispatches each doWrite() call to only the FIRST
        // matching expectation. A short numeric substring like a shop ID collides with
        // digits inside "Found 1 shop(s)"/other output lines and "steals" the match,
        // starving a later, more specific substring expectation (e.g. the domain) even
        // though it is genuinely present in the output — a test-harness footgun, not an
        // application bug. Asserting on the full domain string is unambiguous.
        $this->artisan('shopify:migrate-offline-tokens', ['--dry-run' => true, '--shop' => $target->name])
            ->expectsOutputToContain('Found 1 shop(s)')
            ->expectsOutputToContain($target->name)
            ->expectsOutputToContain('Dry run complete')
            ->assertSuccessful();

        expect(User::query()->whereNull('shopify_offline_access_token_expires_at')->count())->toBe(3);
    });

    it('reports no shops found when --shop targets a domain that is not eligible for migration', function (): void {
        legacyOfflineShop(['shopify_offline_access_token_expires_at' => Carbon::now()->addDay(), 'name' => 'already-migrated.myshopify.com']);

        $apiHelper = Mockery::mock(IApiHelper::class);
        $apiHelper->shouldNotReceive('exchangeNonExpiringOfflineTokenForExpiring');
        $this->app->instance(IApiHelper::class, $apiHelper);

        $this->artisan('shopify:migrate-offline-tokens', ['--dry-run' => true, '--shop' => 'already-migrated.myshopify.com'])
            ->expectsOutputToContain('No legacy offline token shops found.')
            ->assertSuccessful();
    });
});

describe('shopify:migrate-offline-tokens --shop=', function (): void {
    it('migrates only the specified shop', function (): void {
        $target = legacyOfflineShop();
        $other = legacyOfflineShop();

        $apiHelper = Mockery::mock(IApiHelper::class);
        $apiHelper->shouldReceive('exchangeNonExpiringOfflineTokenForExpiring')
            ->once()
            ->with($target->name, 'legacy-offline-token')
            ->andReturn(new ResponseAccess([
                'access_token' => 'new-token',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 31536000,
            ]));
        $this->app->instance(IApiHelper::class, $apiHelper);

        $shopCommand = Mockery::mock(IShopCommand::class);
        $shopCommand->shouldReceive('setAccessToken')->once()->andReturn(true);
        $this->app->instance(IShopCommand::class, $shopCommand);

        $this->artisan('shopify:migrate-offline-tokens', ['--shop' => $target->name])
            ->assertSuccessful();

        expect($other->refresh()->shopify_offline_access_token_expires_at)->toBeNull();
    });
});

describe('shopify:migrate-offline-tokens chunked processing', function (): void {
    it('handles an empty result set without failing', function (): void {
        $this->artisan('shopify:migrate-offline-tokens')
            ->expectsOutputToContain('No legacy offline token shops found.')
            ->assertSuccessful();
    });

    it('reports correct summary counts across success, transient-failure, and orphan outcomes', function (): void {
        $success = legacyOfflineShop();
        $transient = legacyOfflineShop();
        $orphan = legacyOfflineShop();

        $apiHelper = Mockery::mock(IApiHelper::class);

        $apiHelper->shouldReceive('exchangeNonExpiringOfflineTokenForExpiring')
            ->with($success->name, 'legacy-offline-token')
            ->andReturn(new ResponseAccess([
                'access_token' => 'new-token',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 31536000,
            ]));

        $apiHelper->shouldReceive('exchangeNonExpiringOfflineTokenForExpiring')
            ->with($transient->name, 'legacy-offline-token')
            ->andThrow(new ApiException('exchange failed'));

        $apiHelper->shouldReceive('exchangeNonExpiringOfflineTokenForExpiring')
            ->with($orphan->name, 'legacy-offline-token')
            ->andThrow(new ApiException('exchange failed'));

        $liveApi = Mockery::mock(BasicShopifyAPI::class);
        $liveApi->shouldReceive('rest')->andReturn(['errors' => false]);

        $deadApi = Mockery::mock(BasicShopifyAPI::class);
        $deadApi->shouldReceive('rest')->andReturn(['errors' => true, 'status' => 401]);

        $apiHelper->shouldReceive('make')->andReturnSelf();
        $apiHelper->shouldReceive('getApi')->andReturn($liveApi, $deadApi);

        $this->app->instance(IApiHelper::class, $apiHelper);

        $shopCommand = Mockery::mock(IShopCommand::class);
        $shopCommand->shouldReceive('setAccessToken')->once()->andReturn(true);
        $this->app->instance(IShopCommand::class, $shopCommand);

        $this->artisan('shopify:migrate-offline-tokens')
            ->expectsOutputToContain('success: 1')
            ->expectsOutputToContain('transient_failure: 1')
            ->expectsOutputToContain('orphaned: 1')
            ->assertSuccessful();
    });
});
