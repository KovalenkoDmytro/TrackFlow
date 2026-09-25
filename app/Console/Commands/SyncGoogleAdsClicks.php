<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Exceptions\GoogleAds\SyncAlreadyRunning;
use App\Models\PlatformIntegration;
use App\Services\GoogleAdsClickSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class SyncGoogleAdsClicks extends Command
{
    protected $signature = 'google-ads:sync-clicks {--days=3 : Recent calendar days, 1–90} {--integration= : Limit to one integration}';

    protected $description = 'Match storefront click IDs to read-only Google Ads click reports';

    public function handle(GoogleAdsClickSync $sync): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 90) {
            $this->error('Days must be between 1 and 90.');

            return self::FAILURE;
        }
        $query = PlatformIntegration::query()->where('platform', Platform::GoogleAds)->where('active', true);
        if ($this->option('integration')) {
            $query->whereKey($this->option('integration'));
        }
        $failed = false;
        foreach ($query->cursor() as $integration) {
            try {
                $sync->sync($integration, $days);
                $this->info("Integration {$integration->id}: click reports updated.");
            } catch (SyncAlreadyRunning) {
                // Expected overlap between the hourly and daily schedules for the
                // same integration — not an access/auth problem, so it must not
                // flip the command's exit code to failure. Logged at warning
                // (not info) so it stays visible in monitoring: an occasional
                // skip is a harmless schedule overlap, but repeated skips for
                // the same integration can indicate a lock stuck because a
                // worker died mid-run without releasing it.
                Log::warning("google-ads:sync-clicks: sync already running for integration {$integration->id}, skipping.");
                $this->comment("Integration {$integration->id}: sync already running, skipping.");
            } catch (\Throwable $e) {
                // Do not print upstream responses or credentials to scheduler logs.
                $this->error("Integration {$integration->id}: click matching failed. Check Google Ads access and retry.");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
