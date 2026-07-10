<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Alert on shops stuck in an orphaned expiring-offline-token migration state
 * (legacy token dead, no expiring token captured) beyond a grace period —
 * i.e. they did not self-heal via a fresh VerifyShopify re-authentication.
 *
 * Intended to run daily via the scheduler (see routes/console.php).
 *
 * `Log::critical()` alone only writes to `storage/logs/laravel.log` under the
 * default `.env` setup (LOG_STACK=single, no Slack webhook configured) — no
 * one will actually see it. If `OPS_ALERT_EMAIL` is configured, a plain-text
 * summary email is also sent so this alert reaches a human even without a
 * dedicated log-aggregation/alerting stack in place.
 */
class DetectOrphanedShops extends Command
{
    private const GRACE_PERIOD_DAYS = 3;

    protected $signature = 'shopify:detect-orphaned-shops';

    protected $description = 'Alert on shops orphaned by a failed offline token migration beyond the grace period.';

    public function handle(): int
    {
        $threshold = now()->subDays(self::GRACE_PERIOD_DAYS);

        $orphanedShops = User::query()
            ->whereNotNull('orphaned_at')
            ->whereNull('shopify_offline_access_token_expires_at')
            ->where('orphaned_at', '<=', $threshold)
            ->get();

        if ($orphanedShops->isEmpty()) {
            $this->info('No orphaned shops beyond the grace period.');

            return self::SUCCESS;
        }

        foreach ($orphanedShops as $shop) {
            $daysOrphaned = (int) $shop->orphaned_at->diffInDays(now());

            Log::critical('shopify.offline_token_migration.orphan_alert', [
                'shop_id' => $shop->getKey(),
                'shop_domain' => $shop->name,
                'orphaned_at' => $shop->orphaned_at->toIso8601String(),
                'days_orphaned' => $daysOrphaned,
            ]);

            $this->error("ORPHANED: [{$shop->getKey()}] {$shop->name} — orphaned since {$shop->orphaned_at->toIso8601String()} ({$daysOrphaned}d)");
        }

        $this->warn($orphanedShops->count().' orphaned shop(s) require manual investigation.');

        $this->sendOpsAlertEmail($orphanedShops);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, User>  $orphanedShops
     */
    private function sendOpsAlertEmail(Collection $orphanedShops): void
    {
        $recipient = config('mail.ops_alert_email');

        if (empty($recipient)) {
            $this->warn('OPS_ALERT_EMAIL is not configured — skipping email alert (log-only).');

            return;
        }

        $body = $orphanedShops
            ->map(fn (User $shop): string => sprintf(
                '[%d] %s — orphaned since %s (%dd)',
                $shop->getKey(),
                $shop->name,
                $shop->orphaned_at->toIso8601String(),
                (int) $shop->orphaned_at->diffInDays(now()),
            ))
            ->implode("\n");

        try {
            Mail::raw(
                'The following shop(s) have been orphaned by a failed offline token migration for more than '.self::GRACE_PERIOD_DAYS." day(s) and require manual investigation:\n\n{$body}",
                function ($message) use ($recipient): void {
                    $message->to($recipient)->subject('[TrackFlow] Orphaned Shopify shop(s) require attention');
                },
            );
        } catch (Throwable $e) {
            // Never let a mail transport failure turn a detection run into a command failure —
            // the Log::critical() calls above already captured the underlying alert data.
            Log::error('shopify.offline_token_migration.orphan_alert_email_failed', [
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
