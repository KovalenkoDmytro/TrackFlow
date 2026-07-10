<?php

declare(strict_types=1);

namespace App\Actions\Shopify;

use App\Enums\OfflineTokenMigrationResult;
use App\Models\User;
use Gnikyt\BasicShopifyAPI\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsObject;
use Osiset\ShopifyApp\Actions\MigrateShopToExpiringOfflineAccessToken;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Contracts\Commands\Shop as IShopCommand;
use Osiset\ShopifyApp\Objects\Values\AccessToken;
use Osiset\ShopifyApp\Services\OfflineAccessTokenRefresher;
use Throwable;

/**
 * Migrate a single shop from a legacy non-expiring Shopify offline token to
 * an expiring offline token (Shopify deadline: 2027-01-01).
 *
 * Persistence of the exchanged tokens is delegated to the package's own
 * {@see IShopCommand::setAccessToken()}, which already encrypts the refresh
 * token via `Crypt::encryptString()` and writes the raw `shopify_offline_refresh_token`
 * column. This must NOT be duplicated with an Eloquent Attribute cast on the
 * model: {@see OfflineAccessTokenRefresher} reads
 * that column directly (bypassing Eloquent accessors) and calls
 * `Crypt::decryptString()` on it during automatic token refresh — an
 * accessor-based cast would silently double-encrypt/decrypt and break that
 * refresh cycle for every migrated shop.
 */
class MigrateOfflineToken
{
    use AsObject;

    private const LIVENESS_PROBE_PATH = '/admin/shop.json';

    /**
     * Lock key prefix. Must stay byte-for-byte identical to the vendor
     * package's own lock key ({@see MigrateShopToExpiringOfflineAccessToken})
     * so that our out-of-band migration command and the package's
     * `auto_migrate_legacy` code path (if ever enabled) mutually exclude
     * each other instead of racing on the same shop.
     */
    private const LOCK_KEY_PREFIX = 'shopify-offline-migrate:';

    private const LOCK_SECONDS = 30;

    private const LOCK_WAIT_SECONDS = 10;

    public function __construct(
        protected IApiHelper $apiHelper,
        protected IShopCommand $shopCommand,
    ) {}

    public function handle(User $shop): OfflineTokenMigrationResult
    {
        // Cheap pre-lock check — avoids taking the lock at all for shops that
        // were already migrated (the common case on a re-run).
        if ($shop->shopify_offline_access_token_expires_at !== null) {
            return OfflineTokenMigrationResult::AlreadyMigrated;
        }

        $result = null;

        Cache::lock(self::LOCK_KEY_PREFIX.$shop->getId()->toNative(), self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, function () use ($shop, &$result): void {
                // Re-check the guard under the lock against the latest DB state: another
                // worker (cron overlap, concurrent command invocation) may have completed
                // the migration for this exact shop between our pre-lock check and here.
                $shop->refresh();

                if ($shop->shopify_offline_access_token_expires_at !== null) {
                    $result = OfflineTokenMigrationResult::AlreadyMigrated;

                    return;
                }

                $result = $this->exchange($shop);
            });

        return $result;
    }

    private function exchange(User $shop): OfflineTokenMigrationResult
    {
        $domain = $shop->getDomain()->toNative();
        $legacyToken = $shop->getAccessToken()->toNative();

        try {
            $data = $this->apiHelper->exchangeNonExpiringOfflineTokenForExpiring($domain, $legacyToken);
        } catch (Throwable $e) {
            return $this->handleExchangeFailure($shop, $domain, $legacyToken, $this->sanitizeReason($e));
        }

        if (! isset($data['access_token'], $data['refresh_token'], $data['expires_in'], $data['refresh_token_expires_in'])) {
            return $this->handleExchangeFailure(
                $shop,
                $domain,
                $legacyToken,
                'Invalid token exchange response from Shopify (missing expected fields).',
            );
        }

        DB::transaction(function () use ($shop, $data): void {
            $this->shopCommand->setAccessToken(
                $shop->getId(),
                AccessToken::fromNative($data['access_token']),
                $data['refresh_token'],
                now()->addSeconds((int) $data['expires_in']),
                now()->addSeconds((int) $data['refresh_token_expires_in']),
            );

            if ($shop->orphaned_at !== null) {
                $shop->orphaned_at = null;
                $shop->save();
            }
        });

        $shop->refresh();

        Log::info('shopify.offline_token_migration.success', [
            'shop_id' => $shop->getKey(),
            'shop_domain' => $domain,
        ]);

        return OfflineTokenMigrationResult::Success;
    }

    private function handleExchangeFailure(
        User $shop,
        string $domain,
        string $legacyToken,
        string $reason,
    ): OfflineTokenMigrationResult {
        if ($this->legacyTokenIsLive($domain, $legacyToken)) {
            // Exchange failed but the legacy token still works — safe to retry
            // on the next run without any risk of losing API access.
            Log::warning('shopify.offline_token_migration.transient_failure', [
                'shop_id' => $shop->getKey(),
                'shop_domain' => $domain,
                'reason' => $reason,
            ]);

            return OfflineTokenMigrationResult::TransientFailure;
        }

        return $this->markOrphaned($shop, $domain, $reason);
    }

    /**
     * Cheap authenticated probe using the OLD legacy token to determine whether
     * it is still valid. Used only to disambiguate a failed exchange: if the
     * legacy token is already dead, the exchange most likely succeeded on
     * Shopify's side and we simply failed to capture the result (orphan).
     *
     * `Gnikyt\BasicShopifyAPI`'s `Rest::handleFailure()` sets `errors => true`
     * for ANY non-2xx HTTP response — a 429 (rate limit) or 5xx (transient
     * upstream failure) is indistinguishable from a 401/403 (genuinely
     * revoked token) by looking at `errors` alone. Only 401/403 is treated as
     * conclusive proof the legacy token is dead. Everything else — including
     * the probe call itself throwing (network error, timeout, DNS failure) —
     * is inconclusive and must fail safe towards "still alive" so a rate
     * limit or transient outage during a bulk migration run does not get
     * misreported as an Orphaned shop.
     */
    private function legacyTokenIsLive(string $domain, string $legacyToken): bool
    {
        try {
            $response = $this->apiHelper
                ->make(new Session($domain, $legacyToken))
                ->getApi()
                ->rest('GET', self::LIVENESS_PROBE_PATH);
        } catch (Throwable) {
            return true;
        }

        $errors = $response['errors'] ?? false;

        if ($errors === false) {
            return true;
        }

        $status = $response['status'] ?? null;

        return ! in_array($status, [401, 403], true);
    }

    /**
     * Reduce an upstream exception message to a short, log-safe category
     * instead of persisting the raw Shopify OAuth error body verbatim. No
     * token material is ever present in these messages, but the raw body can
     * be arbitrarily large/noisy and is not useful beyond the exception class
     * + a truncated excerpt for grep-ability.
     */
    private function sanitizeReason(Throwable $e): string
    {
        $excerpt = Str::of($e->getMessage())
            ->squish()
            ->limit(200)
            ->toString();

        return sprintf('%s: %s', $e::class, $excerpt);
    }

    private function markOrphaned(User $shop, string $domain, string $reason): OfflineTokenMigrationResult
    {
        // Only alert (and stamp the detection time) the first time this shop is
        // observed as orphaned — re-stamping `orphaned_at` on every command run
        // would reset the grace-period clock and DetectOrphanedShops would never fire.
        if ($shop->orphaned_at === null) {
            $shop->orphaned_at = now();
            $shop->save();

            Log::error('shopify.offline_token_migration.orphaned', [
                'shop_id' => $shop->getKey(),
                'shop_domain' => $domain,
                'reason' => $reason,
                'detected_at' => $shop->orphaned_at->toIso8601String(),
            ]);
        }

        return OfflineTokenMigrationResult::Orphaned;
    }
}
