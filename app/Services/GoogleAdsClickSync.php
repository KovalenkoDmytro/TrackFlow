<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GoogleAds\SyncAlreadyRunning;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class GoogleAdsClickSync
{
    public function __construct(private readonly GoogleAdsClient $client) {}

    public function sync(PlatformIntegration $integration, int $days): void
    {
        if ($days < 1 || $days > 90) {
            throw new \InvalidArgumentException('Days must be between 1 and 90.');
        }
        $lock = Cache::lock('google-ads-click-sync:'.$integration->id, 7200);
        if (! $lock->get()) {
            throw new SyncAlreadyRunning('Click matching is already running.');
        }
        try {
            $credentials = json_decode($integration->credentials, true, flags: JSON_THROW_ON_ERROR);
            $customerId = str_replace('-', '', $credentials['customer_id']);
            $token = $this->client->getAccessToken($credentials['oauth']);
            $account = $this->client->report($credentials, $token, 'SELECT customer.time_zone FROM customer');
            $timezone = $account[0]['customer']['timeZone'] ?? null;
            if (! $timezone) {
                throw new \RuntimeException('Google Ads account timezone is unavailable.');
            }
            $today = CarbonImmutable::now($timezone)->startOfDay();

            // Upgrade retained events without changing their original click identifier.
            // Normalization must match GetGoogleAdsAttribution's TRIM(gclid) <> '' filter,
            // otherwise a whitespace-only gclid could be selected here but never match.
            TrackingEvent::query()->where('user_id', $integration->user_id)
                ->whereNull('gclid_hash')->whereNotNull('gclid')->whereRaw("TRIM(gclid) <> ''")
                ->chunkById(1000, function ($events): void {
                    foreach ($events as $event) {
                        $event->update(['gclid_hash' => hash('sha256', trim((string) $event->gclid))]);
                    }
                });

            for ($offset = 0; $offset < $days; $offset++) {
                $day = $today->subDays($offset);
                $date = $day->toDateString();
                $rows = $this->client->report($credentials, $token,
                    "SELECT click_view.gclid FROM click_view WHERE segments.date = '{$date}'");
                $clicks = [];
                foreach ($rows as $row) {
                    $gclid = $row['clickView']['gclid'] ?? null;
                    if (! is_string($gclid) || trim($gclid) === '') {
                        continue;
                    }
                    // Hashes preserve case-sensitive matching on MySQL's default collation.
                    $hash = hash('sha256', $gclid);
                    $clicks[$hash] = [
                        'platform_integration_id' => $integration->id,
                        'customer_id' => $customerId,
                        'gclid_hash' => $hash,
                        'click_date' => $date,
                        'day_start_utc' => $day->utc()->toDateTimeString(),
                        'checked_at' => now(),
                    ];
                }
                // Do not replace prior evidence if any report page failed.
                DB::transaction(function () use ($integration, $customerId, $date, $clicks): void {
                    DB::table('google_ads_clicks')->where('platform_integration_id', $integration->id)
                        ->where('customer_id', $customerId)->where('click_date', $date)->delete();
                    foreach (array_chunk(array_values($clicks), 500) as $chunk) {
                        DB::table('google_ads_clicks')->insert($chunk);
                    }
                    DB::table('google_ads_click_syncs')->updateOrInsert([
                        'platform_integration_id' => $integration->id,
                        'customer_id' => $customerId,
                        'click_date' => $date,
                    ], ['checked_at' => now()]);
                });
            }
            foreach (['google_ads_clicks', 'google_ads_click_syncs'] as $table) {
                DB::table($table)->where('platform_integration_id', $integration->id)
                    ->where('click_date', '<', $today->subDays(90)->toDateString())->delete();
            }
        } finally {
            $lock->release();
        }
    }
}
