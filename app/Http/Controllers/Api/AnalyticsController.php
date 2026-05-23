<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Analytics\GetEventCountsForPeriod;
use App\Enums\TrackingEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\AnalyticsFilterRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Returns aggregated tracking event counts for the authenticated shop.
 *
 * Supports two filter modes:
 *   - single_day: counts for a single calendar day
 *   - range:      counts aggregated over a custom date range (max 366 days)
 *
 * Defaults to today in the application timezone when no filters are provided.
 */
final class AnalyticsController extends Controller
{
    public function __construct(
        private readonly GetEventCountsForPeriod $getEventCounts,
    ) {}

    public function index(AnalyticsFilterRequest $request): JsonResponse
    {
        $shop = $request->user();
        $timezone = config('app.timezone', 'UTC');
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $mode = $request->input('mode');
        $platform = $request->input('platform');

        [$start, $end, $label] = match ($mode) {
            'range' => $this->buildRangeBounds(
                (string) $request->input('start_date'),
                (string) $request->input('end_date'),
                $timezone,
            ),
            default => $this->buildSingleDayBounds(
                $request->input('date'),
                $today,
                $timezone,
            ),
        };

        $counts = $this->getEventCounts->handle($shop, $start, $end, $platform);
        $total = (int) array_sum(array_map(fn (array $c): int => $c['count'], $counts));

        $days = (int) CarbonImmutable::parse($start->toDateString())->diffInDays(CarbonImmutable::parse($end->toDateString())) + 1;

        return response()->json([
            'filters' => [
                'mode' => $mode,
                'platform' => $platform,
                'date' => $mode === 'single_day' ? $start->toDateString() : null,
                'start_date' => $mode === 'range' ? $start->toDateString() : null,
                'end_date' => $mode === 'range' ? $end->toDateString() : null,
            ],
            'summary' => [
                'period' => [
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                    'label' => $label,
                    'days' => $days,
                ],
                'counts' => $counts,
                'total' => $total,
            ],
            'meta' => [
                'available_events' => array_map(fn (TrackingEventType $c): string => $c->value, TrackingEventType::cases()),
                'max_range_days' => 366,
                'today' => $today->toDateString(),
            ],
        ]);
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable, string}
     */
    private function buildSingleDayBounds(
        ?string $date,
        CarbonImmutable $today,
        string $timezone,
    ): array {
        $day = $date !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone)->startOfDay()
            : $today;

        $start = $day->startOfDay();
        $end = $day->endOfDay();
        $label = $day->format('F j, Y');

        return [$start, $end, $label];
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable, string}
     */
    private function buildRangeBounds(
        string $startDate,
        string $endDate,
        string $timezone,
    ): array {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $startDate, $timezone)->startOfDay();
        $end = CarbonImmutable::createFromFormat('Y-m-d', $endDate, $timezone)->endOfDay();

        $label = $this->formatRangeLabel($start, $end);

        return [$start, $end, $label];
    }

    private function formatRangeLabel(CarbonImmutable $start, CarbonImmutable $end): string
    {
        if ($start->year === $end->year) {
            if ($start->month === $end->month) {
                return $start->format('M j').' – '.$end->format('j, Y');
            }

            return $start->format('M j').' – '.$end->format('M j, Y');
        }

        return $start->format('M j, Y').' – '.$end->format('M j, Y');
    }
}
