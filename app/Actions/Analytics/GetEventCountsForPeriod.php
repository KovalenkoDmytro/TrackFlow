<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\TrackingEventType;
use App\Models\TrackingEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Queries the tracking_events table for event counts in a given period.
 *
 * A single GROUP BY query retrieves all event counts at once, then the result
 * is zero-filled against all canonical TrackingEventType cases so the consumer
 * always receives exactly 9 rows regardless of whether events occurred.
 *
 * @phpstan-type EventCount array{event: string, label: string, count: int}
 */
final class GetEventCountsForPeriod
{
    use AsObject;

    /**
     * Return event counts for the shop between $start (inclusive) and $end (inclusive).
     *
     * $end should be the end of the last day (23:59:59) so the boundary day is included.
     *
     * When $platform is provided, only events that carry at least one tracking
     * identifier for that platform are counted:
     *   - google_ads → gclid is not null
     *   - meta       → fbp or fbc is not null
     *   - tiktok     → ttclid is not null
     *   - ga4        → ga_client_id is not null
     *
     * @return list<EventCount>
     */
    public function handle(User $shop, CarbonImmutable $start, CarbonImmutable $end, ?string $platform = null): array
    {
        $query = TrackingEvent::query()
            ->where('user_id', '=', $shop->getKey())
            ->whereBetween('occurred_at', [$start, $end]);

        match ($platform) {
            'google_ads' => $query->whereNotNull('gclid'),
            'meta' => $query->where(fn ($q) => $q->whereNotNull('fbp')->orWhereNotNull('fbc')),
            'tiktok' => $query->whereNotNull('ttclid'),
            'ga4' => $query->whereNotNull('ga_client_id'),
            default => null,
        };

        /** @var array<string, int> $rawCounts */
        $rawCounts = $query
            ->selectRaw('event, COUNT(*) as count')
            ->groupBy('event')
            ->pluck('count', 'event')
            ->map(fn (mixed $v): int => (int) $v)
            ->all();

        $result = [];

        foreach (TrackingEventType::cases() as $type) {
            $result[] = [
                'event' => $type->value,
                'label' => $type->label(),
                'count' => $rawCounts[$type->value] ?? 0,
            ];
        }

        return $result;
    }
}
