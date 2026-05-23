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
     * @return list<EventCount>
     */
    public function handle(User $shop, CarbonImmutable $start, CarbonImmutable $end): array
    {
        /** @var array<string, int> $rawCounts */
        $rawCounts = TrackingEvent::query()
            ->where('user_id', '=', $shop->getKey())
            ->whereBetween('occurred_at', [$start, $end])
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
