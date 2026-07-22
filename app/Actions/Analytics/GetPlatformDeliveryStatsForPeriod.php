<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\TrackingEventType;
use App\Models\PlatformDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Queries platform_deliveries (joined to tracking_events) for Meta Conversions API
 * delivery status, grouped by event type, in a given period.
 *
 * A single GROUP BY query retrieves attempted/delivered/failed/pending counts and the
 * most recent failure message per event type. The result is zero-filled against all
 * canonical TrackingEventType cases so the consumer always receives exactly 9 rows,
 * with "pending" rows always shown even when zero.
 *
 * Status mapping:
 *   - delivered                    → delivered
 *   - failed, partial_failure      → failed (merged into a single count)
 *   - queued                       → pending
 *
 * @phpstan-type PlatformDeliveryStat array{
 *     event: string,
 *     label: string,
 *     attempted: int,
 *     delivered: int,
 *     failed: int,
 *     pending: int,
 *     last_error: string|null,
 * }
 */
final class GetPlatformDeliveryStatsForPeriod
{
    use AsObject;

    /**
     * Return per-event delivery stats for the shop between $start and $end (inclusive),
     * scoped to the given $platform.
     *
     * @return list<PlatformDeliveryStat>
     */
    public function handle(User $shop, CarbonImmutable $start, CarbonImmutable $end, string $platform): array
    {
        /** @var array<string, array{attempted: int, delivered: int, failed: int, pending: int}> $rawStats */
        $rawStats = PlatformDelivery::query()
            ->join('tracking_events', 'tracking_events.id', '=', 'platform_deliveries.tracking_event_id')
            ->where('tracking_events.user_id', '=', $shop->getKey())
            ->where('platform_deliveries.platform', '=', $platform)
            ->whereBetween('tracking_events.occurred_at', [$start, $end])
            ->selectRaw('tracking_events.event as event')
            ->selectRaw('COUNT(*) as attempted')
            ->selectRaw("SUM(CASE WHEN platform_deliveries.status = 'delivered' THEN 1 ELSE 0 END) as delivered")
            ->selectRaw("SUM(CASE WHEN platform_deliveries.status IN ('failed', 'partial_failure') THEN 1 ELSE 0 END) as failed")
            ->selectRaw("SUM(CASE WHEN platform_deliveries.status = 'queued' THEN 1 ELSE 0 END) as pending")
            ->groupBy('tracking_events.event')
            ->get()
            ->keyBy('event')
            ->map(fn (mixed $row): array => [
                'attempted' => (int) $row->attempted,
                'delivered' => (int) $row->delivered,
                'failed' => (int) $row->failed,
                'pending' => (int) $row->pending,
            ])
            ->all();

        /** @var array<string, string> $lastErrors */
        $lastErrors = $this->latestErrorsByEvent($shop, $start, $end, $platform);

        $result = [];

        foreach (TrackingEventType::cases() as $type) {
            $stats = $rawStats[$type->value] ?? [
                'attempted' => 0,
                'delivered' => 0,
                'failed' => 0,
                'pending' => 0,
            ];

            $result[] = [
                'event' => $type->value,
                'label' => $type->label(),
                'attempted' => $stats['attempted'],
                'delivered' => $stats['delivered'],
                'failed' => $stats['failed'],
                'pending' => $stats['pending'],
                'last_error' => $lastErrors[$type->value] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Return the most recent failure response_body per event type, keyed by event.
     *
     * @return array<string, string>
     */
    private function latestErrorsByEvent(User $shop, CarbonImmutable $start, CarbonImmutable $end, string $platform): array
    {
        $latestFailureIds = PlatformDelivery::query()
            ->join('tracking_events', 'tracking_events.id', '=', 'platform_deliveries.tracking_event_id')
            ->where('tracking_events.user_id', '=', $shop->getKey())
            ->where('platform_deliveries.platform', '=', $platform)
            ->whereIn('platform_deliveries.status', ['failed', 'partial_failure'])
            ->whereBetween('tracking_events.occurred_at', [$start, $end])
            ->whereNotNull('platform_deliveries.response_body')
            ->selectRaw('tracking_events.event as event')
            ->selectRaw('MAX(platform_deliveries.id) as id')
            ->groupBy('tracking_events.event')
            ->pluck('id', 'event');

        if ($latestFailureIds->isEmpty()) {
            return [];
        }

        return PlatformDelivery::query()
            ->join('tracking_events', 'tracking_events.id', '=', 'platform_deliveries.tracking_event_id')
            ->whereIn('platform_deliveries.id', $latestFailureIds->values())
            ->select(['tracking_events.event as event', 'platform_deliveries.response_body as response_body'])
            ->get()
            ->pluck('response_body', 'event')
            ->map(fn (mixed $v): string => (string) $v)
            ->all();
    }
}
