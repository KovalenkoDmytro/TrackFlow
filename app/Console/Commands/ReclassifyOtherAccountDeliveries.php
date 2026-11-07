<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Services\GoogleAdsOtherAccountClassifier;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * One-off data fix for Google Ads deliveries recorded as `partial_failure` before the
 * `other_account` status existed. A row moves only when it carries the stored
 * INVALID_CUSTOMER_FOR_CLICK reason and its click is provably absent from the current
 * account's sufficiently synced click report. The previous status is kept in response_body
 * (`previous_status`) so the change can be reversed. Safe to re-run: moved rows no longer match.
 */
final class ReclassifyOtherAccountDeliveries extends Command
{
    private const string LAST_ERROR = 'partial_failure: '.GoogleAdsOtherAccountClassifier::ERROR_CODE;

    protected $signature = 'google-ads:reclassify-other-account
        {integration? : Google Ads integration ID (default: every Google Ads integration)}
        {--dry-run : Print per-integration counts without changing anything}';

    protected $description = 'Reclassify Google Ads partial failures caused by clicks from another account as other_account';

    public function handle(GoogleAdsOtherAccountClassifier $classifier): int
    {
        $integrations = PlatformIntegration::query()
            ->where('platform', Platform::GoogleAds)
            ->when($this->argument('integration') !== null, fn ($q) => $q->whereKey((int) $this->argument('integration')))
            ->get();

        if ($integrations->isEmpty()) {
            $this->error('No matching Google Ads integration found.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach ($integrations as $integration) {
            $customerId = $classifier->customerId($integration);

            if (! $classifier->hasSyncCoverage($integration, $customerId)) {
                $rows[] = [$integration->getKey(), 0, 'skipped: not enough synced click-report days'];

                continue;
            }

            $moved = $this->reclassify($integration, $classifier, $customerId, $dryRun);
            $reset = ! $dryRun && $moved > 0 && $this->resetLastError($integration);
            $rows[] = [$integration->getKey(), $moved, $dryRun ? 'dry run' : ($reset ? 'last_error reset' : 'ok')];
        }

        $this->table(['Integration', $dryRun ? 'Would reclassify' : 'Reclassified', 'Note'], $rows);

        return self::SUCCESS;
    }

    private function reclassify(PlatformIntegration $integration, GoogleAdsOtherAccountClassifier $classifier, string $customerId, bool $dryRun): int
    {
        $moved = 0;

        PlatformDelivery::query()
            ->with('trackingEvent')
            ->where('platform_integration_id', $integration->getKey())
            ->where('platform', Platform::GoogleAds->value)
            ->where('status', 'partial_failure')
            ->where('response_body', 'like', '%'.GoogleAdsOtherAccountClassifier::ERROR_CODE.'%')
            ->chunkById(200, function (Collection $deliveries) use ($integration, $classifier, $customerId, $dryRun, &$moved): void {
                foreach ($deliveries as $delivery) {
                    $body = json_decode((string) $delivery->response_body, true);
                    $codes = is_array($body) && is_array($body['codes'] ?? null) ? $body['codes'] : [];

                    if (($codes[0] ?? null) !== GoogleAdsOtherAccountClassifier::ERROR_CODE
                        || $delivery->trackingEvent === null
                        || ! $classifier->isUnmatchedClick($integration, $delivery->trackingEvent)) {
                        continue;
                    }

                    $moved++;

                    if (! $dryRun) {
                        $delivery->update([
                            'status' => GoogleAdsOtherAccountClassifier::STATUS,
                            'response_body' => (string) json_encode(
                                $body + ['previous_status' => 'partial_failure', 'customer_id' => $customerId],
                                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                            ),
                        ]);
                    }
                }
            });

        return $moved;
    }

    /** Clear the integration error only when it was this partial failure and nothing else is failing. */
    private function resetLastError(PlatformIntegration $integration): bool
    {
        $integration->refresh();

        if ($integration->last_error !== self::LAST_ERROR) {
            return false;
        }

        $otherFailures = PlatformDelivery::query()
            ->where('platform_integration_id', $integration->getKey())
            ->whereIn('status', ['failed', 'partial_failure'])
            ->exists();

        if ($otherFailures) {
            return false;
        }

        $integration->forceFill(['last_error' => null, 'last_error_at' => null])->save();

        return true;
    }
}
