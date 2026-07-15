<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Providers\AppServiceProvider;
use Gnikyt\BasicShopifyAPI\ResponseAccess;
use Illuminate\Support\Facades\Log;
use Osiset\ShopifyApp\Actions\InstallShop;
use Osiset\ShopifyApp\Objects\Enums\AuthMode;
use Osiset\ShopifyApp\Services\ApiHelper;
use Throwable;

/**
 * Extends the vendor's ApiHelper purely to log the exchange failures that
 * {@see InstallShop::__invoke()} otherwise swallows
 * silently (it catches Exception and returns `completed => false` with no
 * logging at all). Bound over the `IApiHelper` contract in
 * {@see AppServiceProvider::boot()} — the vendor file itself is
 * never edited.
 *
 * Without this, a broken token exchange during Shopify's managed-installation
 * flow (see App\Actions\Shopify\AuthenticateShopify) is completely invisible in
 * our logs: the merchant just gets silently redirected back through
 * /authenticate in a loop.
 */
final class LoggingApiHelper extends ApiHelper
{
    public function performOfflineTokenExchange(string $token): ResponseAccess
    {
        try {
            return parent::performOfflineTokenExchange($token);
        } catch (Throwable $e) {
            Log::error('shopify.auth.token_exchange_failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function getAccessData(string $code, ?AuthMode $grantMode = null): ResponseAccess
    {
        try {
            return parent::getAccessData($code, $grantMode);
        } catch (Throwable $e) {
            Log::error('shopify.auth.code_exchange_failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function refreshOfflineAccessToken(string $refreshToken): ResponseAccess
    {
        try {
            return parent::refreshOfflineAccessToken($refreshToken);
        } catch (Throwable $e) {
            Log::error('shopify.auth.token_refresh_failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function exchangeNonExpiringOfflineTokenForExpiring(
        string $shopDomain,
        string $nonExpiringOfflineAccessToken,
    ): ResponseAccess {
        try {
            return parent::exchangeNonExpiringOfflineTokenForExpiring($shopDomain, $nonExpiringOfflineAccessToken);
        } catch (Throwable $e) {
            Log::error('shopify.auth.token_migration_failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
