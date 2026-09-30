<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether the traffic a shop captures comes from a different Google Ads
 * account than the connected one. Click matching is the only evidence: once enough
 * report days of the CURRENT customer id were synced and none of the shop's recent
 * click IDs appear in them, the ads sending the traffic are not in this account.
 */
final class GoogleAdsClickOwnership
{
    /**
     * @return array{customer_id: string, checked_days: int, click_ids: int}|null
     *                                                                            Null when the evidence is inconclusive (sync too young, no click IDs, or at least one match).
     */
    public function foreignClicks(PlatformIntegration $integration): ?array
    {
        $credentials = json_decode((string) $integration->credentials, true);
        $customerId = str_replace('-', '', is_array($credentials) ? (string) ($credentials['customer_id'] ?? '') : '');
        if ($customerId === '') {
            return null;
        }

        // Rows kept from a previously connected customer id say nothing about this account.
        $checkedDays = DB::table('google_ads_click_syncs')
            ->where('platform_integration_id', $integration->getKey())
            ->where('customer_id', $customerId)->count();
        if ($checkedDays < (int) config('alerts.click_ownership_min_synced_days')) {
            return null;
        }

        $events = TrackingEvent::query()->where('user_id', $integration->user_id)
            ->where('occurred_at', '>=', now()->subDays(30))
            ->whereNotNull('gclid_hash');
        $clickIds = (clone $events)->distinct()->count('gclid_hash');
        if ($clickIds === 0) {
            return null;
        }

        $matched = (clone $events)->whereExists(fn ($query) => $query->selectRaw('1')->from('google_ads_clicks')
            ->where('platform_integration_id', $integration->getKey())
            ->where('customer_id', $customerId)
            ->whereColumn('google_ads_clicks.gclid_hash', 'tracking_events.gclid_hash'))->exists();

        return $matched ? null : ['customer_id' => $customerId, 'checked_days' => $checkedDays, 'click_ids' => $clickIds];
    }
}
