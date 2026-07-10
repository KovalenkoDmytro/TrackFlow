<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Shopify\MigrateOfflineToken;
use App\Enums\OfflineTokenMigrationResult;
use App\Models\User;
use Gnikyt\BasicShopifyAPI\Deferrers\Sleep;
use Illuminate\Console\Command;

/**
 * Bulk-migrate shops still on legacy non-expiring Shopify offline tokens to
 * expiring offline tokens. Shopify's rate-limit deferrer
 * ({@see Sleep}, configured in
 * `config/shopify-app.php`) already throttles outgoing API calls, so this
 * command does not add its own sleep/backoff loop.
 */
class MigrateExpiringOfflineTokens extends Command
{
    private const CHUNK_SIZE = 50;

    protected $signature = 'shopify:migrate-offline-tokens
        {--dry-run : List affected shops without making any Shopify API calls}
        {--shop= : Migrate a single shop by domain (for canary rollout)}';

    protected $description = 'Migrate legacy non-expiring Shopify offline tokens to expiring offline tokens.';

    public function handle(): int
    {
        $query = User::query()->whereNull('shopify_offline_access_token_expires_at');

        if (is_string($shopDomain = $this->option('shop')) && $shopDomain !== '') {
            $query->where('name', $shopDomain);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No legacy offline token shops found.');

            return self::SUCCESS;
        }

        $this->info("Found {$total} shop(s) with legacy offline tokens.");

        if ($this->option('dry-run')) {
            $query->orderBy('id')->chunkById(self::CHUNK_SIZE, function ($shops): void {
                foreach ($shops as $shop) {
                    $this->line(" - [{$shop->getKey()}] {$shop->name}");
                }
            });

            $this->info('Dry run complete — no API calls were made.');

            return self::SUCCESS;
        }

        $stats = array_fill_keys(
            array_map(fn (OfflineTokenMigrationResult $case) => $case->value, OfflineTokenMigrationResult::cases()),
            0,
        );

        $processed = 0;

        $query->orderBy('id')->chunkById(self::CHUNK_SIZE, function ($shops) use (&$stats, &$processed, $total): void {
            foreach ($shops as $shop) {
                $result = MigrateOfflineToken::run($shop);
                $stats[$result->value]++;
                $processed++;

                $this->info("Migrated {$processed}/{$total} — {$shop->name}: {$result->value}");
            }
        });

        $this->newLine();
        $this->info('Migration summary:');

        foreach ($stats as $status => $count) {
            $this->line("  {$status}: {$count}");
        }

        return self::SUCCESS;
    }
}
