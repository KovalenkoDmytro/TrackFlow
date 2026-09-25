<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\Platform;
use App\Enums\TrackingEventType;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class GetGoogleAdsAttribution
{
    public function handle(User $shop, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $integration = PlatformIntegration::query()->where('user_id', $shop->id)
            ->where('platform', Platform::GoogleAds)->where('active', true)->first();
        $credentials = $integration ? json_decode($integration->credentials, true) : [];
        $customerId = str_replace('-', '', $credentials['customer_id'] ?? '');
        $events = TrackingEvent::query()->where('user_id', $shop->id)
            ->whereBetween('occurred_at', [$start, $end])
            ->whereNotNull('gclid')->whereRaw("TRIM(gclid) <> ''");
        $tagged = (clone $events)->selectRaw('event, COUNT(*) as total')->groupBy('event')->pluck('total', 'event');
        $matched = (clone $events)->whereExists(function ($query) use ($integration, $customerId): void {
            $query->selectRaw('1')->from('google_ads_clicks')
                ->where('platform_integration_id', $integration?->id ?? 0)
                ->where('customer_id', $customerId)
                ->whereColumn('google_ads_clicks.gclid_hash', 'tracking_events.gclid_hash')
                ->whereColumn('google_ads_clicks.day_start_utc', '<=', 'tracking_events.occurred_at');
        })->selectRaw('event, COUNT(*) as total')->groupBy('event')->pluck('total', 'event');
        $counts = [];
        foreach (TrackingEventType::cases() as $type) {
            $count = (int) ($matched[$type->value] ?? 0);
            $counts[] = ['event' => $type->value, 'label' => $type->label(), 'count' => $count,
                'unverified' => (int) ($tagged[$type->value] ?? 0) - $count];
        }
        $sync = DB::table('google_ads_click_syncs')->where('platform_integration_id', $integration?->id ?? 0)
            ->where('customer_id', $customerId);

        return [
            'counts' => $counts,
            'total' => array_sum(array_column($counts, 'count')),
            'unverified_total' => array_sum(array_column($counts, 'unverified')),
            'attribution' => [
                'connected' => $integration !== null,
                'customer_id' => $customerId ?: null,
                'last_checked_at' => (clone $sync)->max('checked_at'),
                'checked_days' => (clone $sync)->count(),
            ],
        ];
    }
}
