<?php

declare(strict_types=1);

use App\Actions\Shopify\MigrateOfflineToken;
use App\Enums\OfflineTokenMigrationResult;
use App\Models\User;
use Gnikyt\BasicShopifyAPI\BasicShopifyAPI;
use Gnikyt\BasicShopifyAPI\ResponseAccess;
use Illuminate\Support\Carbon;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Contracts\Commands\Shop as IShopCommand;
use Osiset\ShopifyApp\Exceptions\ApiException;
use Osiset\ShopifyApp\Objects\Values\AccessToken;

function legacyShop(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'password' => 'legacy-offline-token',
        'shopify_offline_access_token_expires_at' => null,
        'shopify_offline_refresh_token' => null,
        'shopify_offline_refresh_token_expires_at' => null,
        'orphaned_at' => null,
    ], $attributes));
}

describe('MigrateOfflineToken: idempotency guard', function (): void {
    it('does not call the exchange API when the shop already has an expiry timestamp', function (): void {
        $shop = legacyShop([
            'shopify_offline_access_token_expires_at' => Carbon::now()->addDay(),
        ]);

        $apiHelper = Mockery::mock(IApiHelper::class);
        $apiHelper->shouldNotReceive('exchangeNonExpiringOfflineTokenForExpiring');
        $this->app->instance(IApiHelper::class, $apiHelper);

        $shopCommand = Mockery::mock(IShopCommand::class);
        $shopCommand->shouldNotReceive('setAccessToken');
        $this->app->instance(IShopCommand::class, $shopCommand);

        $result = MigrateOfflineToken::run($shop);

        expect($result)->toBe(OfflineTokenMigrationResult::AlreadyMigrated);
    });
});

describe('MigrateOfflineToken: successful exchange', function (): void {
    it('persists all four fields transactionally and clears any prior orphan flag', function (): void {
        $shop = legacyShop(['orphaned_at' => Carbon::now()->subDay()]);

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

        $shopCommand = Mockery::mock(IShopCommand::class);
        $shopCommand->shouldReceive('setAccessToken')
            ->once()
            ->withArgs(function ($shopId, AccessToken $token, ?string $refresh, $accessExp, $refreshExp) use ($shop) {
                return $shopId->toNative() === $shop->getKey()
                    && $token->toNative() === 'new-expiring-access-token'
                    && $refresh === 'new-refresh-token'
                    && $accessExp !== null
                    && $refreshExp !== null;
            })
            ->andReturn(true);
        $this->app->instance(IShopCommand::class, $shopCommand);

        $result = MigrateOfflineToken::run($shop);

        expect($result)->toBe(OfflineTokenMigrationResult::Success);

        $shop->refresh();
        expect($shop->orphaned_at)->toBeNull();
    });
});

describe('MigrateOfflineToken: liveness probe on exchange failure', function (): void {
    it('returns TransientFailure and does not mutate state when the legacy token is still alive', function (): void {
        $shop = legacyShop();

        $apiHelper = Mockery::mock(IApiHelper::class);
        $apiHelper->shouldReceive('exchangeNonExpiringOfflineTokenForExpiring')
            ->once()
            ->andThrow(new ApiException('exchange failed'));

        $liveApi = Mockery::mock(BasicShopifyAPI::class);
        $liveApi->shouldReceive('rest')->once()->andReturn(['errors' => false, 'body' => ['shop' => ['id' => 1]]]);

        $apiHelper->shouldReceive('make')->once()->andReturnSelf();
        $apiHelper->shouldReceive('getApi')->once()->andReturn($liveApi);
        $this->app->instance(IApiHelper::class, $apiHelper);

        $shopCommand = Mockery::mock(IShopCommand::class);
        $shopCommand->shouldNotReceive('setAccessToken');
        $this->app->instance(IShopCommand::class, $shopCommand);

        $result = MigrateOfflineToken::run($shop);

        expect($result)->toBe(OfflineTokenMigrationResult::TransientFailure);

        $shop->refresh();
        expect($shop->orphaned_at)->toBeNull()
            ->and($shop->shopify_offline_access_token_expires_at)->toBeNull();
    });

    it('returns Orphaned and stamps orphaned_at when the legacy token is dead', function (): void {
        $shop = legacyShop();

        $apiHelper = Mockery::mock(IApiHelper::class);
        $apiHelper->shouldReceive('exchangeNonExpiringOfflineTokenForExpiring')
            ->once()
            ->andThrow(new ApiException('exchange failed'));

        $deadApi = Mockery::mock(BasicShopifyAPI::class);
        $deadApi->shouldReceive('rest')->once()->andReturn(['errors' => true, 'status' => 401]);

        $apiHelper->shouldReceive('make')->once()->andReturnSelf();
        $apiHelper->shouldReceive('getApi')->once()->andReturn($deadApi);
        $this->app->instance(IApiHelper::class, $apiHelper);

        $shopCommand = Mockery::mock(IShopCommand::class);
        $shopCommand->shouldNotReceive('setAccessToken');
        $this->app->instance(IShopCommand::class, $shopCommand);

        $result = MigrateOfflineToken::run($shop);

        expect($result)->toBe(OfflineTokenMigrationResult::Orphaned);

        $shop->refresh();
        expect($shop->orphaned_at)->not->toBeNull();
    });

    it('does not overwrite an already-known orphaned_at timestamp on a repeat failure', function (): void {
        $firstDetection = Carbon::now()->subDays(2);
        $shop = legacyShop(['orphaned_at' => $firstDetection]);

        $apiHelper = Mockery::mock(IApiHelper::class);
        $apiHelper->shouldReceive('exchangeNonExpiringOfflineTokenForExpiring')
            ->once()
            ->andThrow(new ApiException('exchange failed'));

        $deadApi = Mockery::mock(BasicShopifyAPI::class);
        $deadApi->shouldReceive('rest')->once()->andReturn(['errors' => true, 'status' => 401]);

        $apiHelper->shouldReceive('make')->once()->andReturnSelf();
        $apiHelper->shouldReceive('getApi')->once()->andReturn($deadApi);
        $this->app->instance(IApiHelper::class, $apiHelper);

        $shopCommand = Mockery::mock(IShopCommand::class);
        $shopCommand->shouldNotReceive('setAccessToken');
        $this->app->instance(IShopCommand::class, $shopCommand);

        $result = MigrateOfflineToken::run($shop);

        expect($result)->toBe(OfflineTokenMigrationResult::Orphaned);

        $shop->refresh();
        expect($shop->orphaned_at->toDateTimeString())->toBe($firstDetection->toDateTimeString());
    });

    it('treats a liveness probe that itself throws (e.g. network failure) as inconclusive and returns TransientFailure', function (): void {
        $shop = legacyShop();

        $apiHelper = Mockery::mock(IApiHelper::class);
        $apiHelper->shouldReceive('exchangeNonExpiringOfflineTokenForExpiring')
            ->once()
            ->andThrow(new ApiException('exchange failed'));

        // The probe call itself blows up (connection timeout, DNS failure, etc.)
        // rather than returning a clean errors=true/false response. This is
        // inconclusive — not proof the legacy token is dead — so it must fail
        // safe towards a retry rather than a false Orphaned alert.
        $apiHelper->shouldReceive('make')
            ->once()
            ->andThrow(new ApiException('connection timed out'));
        $apiHelper->shouldNotReceive('getApi');
        $this->app->instance(IApiHelper::class, $apiHelper);

        $shopCommand = Mockery::mock(IShopCommand::class);
        $shopCommand->shouldNotReceive('setAccessToken');
        $this->app->instance(IShopCommand::class, $shopCommand);

        $result = MigrateOfflineToken::run($shop);

        expect($result)->toBe(OfflineTokenMigrationResult::TransientFailure);

        $shop->refresh();
        expect($shop->orphaned_at)->toBeNull();
    });

    it('treats a liveness probe rest() call that throws as inconclusive and returns TransientFailure', function (): void {
        $shop = legacyShop();

        $apiHelper = Mockery::mock(IApiHelper::class);
        $apiHelper->shouldReceive('exchangeNonExpiringOfflineTokenForExpiring')
            ->once()
            ->andThrow(new ApiException('exchange failed'));

        $flakyApi = Mockery::mock(BasicShopifyAPI::class);
        $flakyApi->shouldReceive('rest')->once()->andThrow(new ApiException('network unreachable'));

        $apiHelper->shouldReceive('make')->once()->andReturnSelf();
        $apiHelper->shouldReceive('getApi')->once()->andReturn($flakyApi);
        $this->app->instance(IApiHelper::class, $apiHelper);

        $shopCommand = Mockery::mock(IShopCommand::class);
        $shopCommand->shouldNotReceive('setAccessToken');
        $this->app->instance(IShopCommand::class, $shopCommand);

        $result = MigrateOfflineToken::run($shop);

        expect($result)->toBe(OfflineTokenMigrationResult::TransientFailure);

        $shop->refresh();
        expect($shop->orphaned_at)->toBeNull();
    });

    it('treats a 429 rate-limit response from the probe as inconclusive and returns TransientFailure (not Orphaned)', function (): void {
        $shop = legacyShop();

        $apiHelper = Mockery::mock(IApiHelper::class);
        $apiHelper->shouldReceive('exchangeNonExpiringOfflineTokenForExpiring')
            ->once()
            ->andThrow(new ApiException('exchange failed'));

        $throttledApi = Mockery::mock(BasicShopifyAPI::class);
        $throttledApi->shouldReceive('rest')->once()->andReturn(['errors' => true, 'status' => 429]);

        $apiHelper->shouldReceive('make')->once()->andReturnSelf();
        $apiHelper->shouldReceive('getApi')->once()->andReturn($throttledApi);
        $this->app->instance(IApiHelper::class, $apiHelper);

        $shopCommand = Mockery::mock(IShopCommand::class);
        $shopCommand->shouldNotReceive('setAccessToken');
        $this->app->instance(IShopCommand::class, $shopCommand);

        $result = MigrateOfflineToken::run($shop);

        expect($result)->toBe(OfflineTokenMigrationResult::TransientFailure);

        $shop->refresh();
        expect($shop->orphaned_at)->toBeNull();
    });

    it('treats a 500 response from the probe as inconclusive and returns TransientFailure (not Orphaned)', function (): void {
        $shop = legacyShop();

        $apiHelper = Mockery::mock(IApiHelper::class);
        $apiHelper->shouldReceive('exchangeNonExpiringOfflineTokenForExpiring')
            ->once()
            ->andThrow(new ApiException('exchange failed'));

        $flakyApi = Mockery::mock(BasicShopifyAPI::class);
        $flakyApi->shouldReceive('rest')->once()->andReturn(['errors' => true, 'status' => 500]);

        $apiHelper->shouldReceive('make')->once()->andReturnSelf();
        $apiHelper->shouldReceive('getApi')->once()->andReturn($flakyApi);
        $this->app->instance(IApiHelper::class, $apiHelper);

        $shopCommand = Mockery::mock(IShopCommand::class);
        $shopCommand->shouldNotReceive('setAccessToken');
        $this->app->instance(IShopCommand::class, $shopCommand);

        $result = MigrateOfflineToken::run($shop);

        expect($result)->toBe(OfflineTokenMigrationResult::TransientFailure);

        $shop->refresh();
        expect($shop->orphaned_at)->toBeNull();
    });
});

describe('MigrateOfflineToken: concurrent execution (cron overlap) race', function (): void {
    // These two tests intentionally use the REAL container-bound IShopCommand
    // (kyon147's Storage\Commands\Shop) rather than a mock: only the real
    // implementation actually persists shopify_offline_access_token_expires_at
    // to the database, which is exactly what the idempotency guard and the
    // race scenario below depend on.
    it('does not double-exchange when the same shop row is processed twice with fresh (unshared) model instances', function (): void {
        $persistedShop = legacyShop();

        // Two "workers" each load their own copy of the same DB row before either commits —
        // this is the realistic cron-overlap scenario, since chunkById() in the console
        // command loads a fresh model per run rather than sharing an in-memory instance.
        $workerA = User::query()->find($persistedShop->getKey());
        $workerB = User::query()->find($persistedShop->getKey());

        $apiHelper = Mockery::mock(IApiHelper::class);

        // Worker A wins the race: exchange succeeds and revokes the legacy token on Shopify's side.
        $apiHelper->shouldReceive('exchangeNonExpiringOfflineTokenForExpiring')
            ->once()
            ->with($persistedShop->name, 'legacy-offline-token')
            ->andReturn(new ResponseAccess([
                'access_token' => 'new-expiring-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 31536000,
            ]));

        $this->app->instance(IApiHelper::class, $apiHelper);

        // Order matters: worker A processes first (simulating it winning the race).
        $resultA = MigrateOfflineToken::run($workerA);
        $resultB = MigrateOfflineToken::run($workerB);

        expect($resultA)->toBe(OfflineTokenMigrationResult::Success);

        // Worker B acquires the per-shop lock AFTER worker A has released it (having
        // committed the migration) and re-checks the idempotency guard against the
        // latest DB row under the lock — via $shop->refresh() — before ever attempting
        // an exchange. It therefore short-circuits to AlreadyMigrated instead of racing
        // the exchange call a second time and getting misclassified as Orphaned.
        expect($resultB)->toBe(OfflineTokenMigrationResult::AlreadyMigrated);

        $persistedShop->refresh();
        expect($persistedShop->shopify_offline_access_token_expires_at)->not->toBeNull()
            ->and($persistedShop->shopify_offline_refresh_token_expires_at)->not->toBeNull()
            ->and($persistedShop->orphaned_at)->toBeNull();
    });

    it('skips the second call via the idempotency guard when the shop instance is shared and refreshed in place', function (): void {
        $shop = legacyShop();

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

        $firstResult = MigrateOfflineToken::run($shop);
        // MigrateOfflineToken::handle() calls $shop->refresh() on success, mutating the
        // same in-memory instance — a second call against that very instance must be
        // caught by the idempotency guard without touching the API again.
        $secondResult = MigrateOfflineToken::run($shop);

        expect($firstResult)->toBe(OfflineTokenMigrationResult::Success)
            ->and($secondResult)->toBe(OfflineTokenMigrationResult::AlreadyMigrated);
    });
});
