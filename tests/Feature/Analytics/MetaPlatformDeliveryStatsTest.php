<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\TrackingEventType;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;

/**
 * @return array{TrackingEvent, PlatformIntegration}
 */
function createMetaTrackingEvent(User $shop, TrackingEventType $type, string $occurredAt): array
{
    $trackingEvent = TrackingEvent::factory()
        ->forUser($shop)
        ->forEvent($type)
        ->occurredAt($occurredAt)
        ->create();

    $integration = PlatformIntegration::query()->firstOrCreate(
        ['user_id' => $shop->getKey(), 'platform' => Platform::Meta],
        ['active' => true, 'credentials' => null, 'settings' => []],
    );

    return [$trackingEvent, $integration];
}

function createMetaDelivery(
    TrackingEvent $trackingEvent,
    PlatformIntegration $integration,
    string $status,
    string $createdAt,
    ?string $responseBody = null,
): PlatformDelivery {
    return PlatformDelivery::query()->forceCreate([
        'tracking_event_id' => $trackingEvent->getKey(),
        'platform_integration_id' => $integration->getKey(),
        'platform' => Platform::Meta->value,
        'status' => $status,
        'attempts' => 1,
        'response_code' => $status === 'delivered' ? 200 : 400,
        'response_body' => $responseBody,
        'sent_at' => $createdAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

describe('GET /api/analytics?platform=meta — delivery stats', function (): void {
    it('aggregates delivered, failed (merged with partial_failure), and pending counts per event', function (): void {
        $shop = User::factory()->create();

        [$purchaseEvent, $integration] = createMetaTrackingEvent($shop, TrackingEventType::Purchase, '2026-05-01 10:00:00');
        createMetaDelivery($purchaseEvent, $integration, 'delivered', '2026-05-01 10:00:05');

        [$purchaseEvent2] = createMetaTrackingEvent($shop, TrackingEventType::Purchase, '2026-05-01 11:00:00');
        createMetaDelivery($purchaseEvent2, $integration, 'failed', '2026-05-01 11:00:05', 'Invalid access token');

        [$purchaseEvent3] = createMetaTrackingEvent($shop, TrackingEventType::Purchase, '2026-05-01 12:00:00');
        createMetaDelivery($purchaseEvent3, $integration, 'partial_failure', '2026-05-01 12:00:05', 'Event partially rejected');

        [$purchaseEvent4] = createMetaTrackingEvent($shop, TrackingEventType::Purchase, '2026-05-01 13:00:00');
        createMetaDelivery($purchaseEvent4, $integration, 'queued', '2026-05-01 13:00:05');

        $response = $this->actingAs($shop)->getJson('/api/analytics?mode=single_day&date=2026-05-01&platform=meta');

        $response->assertOk();

        $response->assertJsonStructure([
            'filters' => ['mode', 'platform'],
            'summary' => [
                'period' => ['start', 'end', 'label', 'days'],
                'delivery_stats',
                'totals' => ['attempted', 'delivered', 'failed', 'pending'],
            ],
            'meta' => ['available_events', 'max_range_days', 'today'],
        ]);

        $purchaseRow = collect($response->json('summary.delivery_stats'))->firstWhere('event', 'purchase');

        expect($purchaseRow['attempted'])->toBe(4)
            ->and($purchaseRow['delivered'])->toBe(1)
            ->and($purchaseRow['failed'])->toBe(2)
            ->and($purchaseRow['pending'])->toBe(1);

        expect($response->json('summary.totals.attempted'))->toBe(4)
            ->and($response->json('summary.totals.delivered'))->toBe(1)
            ->and($response->json('summary.totals.failed'))->toBe(2)
            ->and($response->json('summary.totals.pending'))->toBe(1);
    });

    it('zero-fills all 9 canonical event types even with no deliveries', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->getJson('/api/analytics?mode=single_day&date=2026-05-01&platform=meta');

        $response->assertOk();

        $stats = $response->json('summary.delivery_stats');
        expect($stats)->toHaveCount(9);

        $returnedEvents = array_column($stats, 'event');
        foreach (TrackingEventType::cases() as $type) {
            expect($returnedEvents)->toContain($type->value);
        }

        foreach ($stats as $row) {
            expect($row['attempted'])->toBe(0)
                ->and($row['delivered'])->toBe(0)
                ->and($row['failed'])->toBe(0)
                ->and($row['pending'])->toBe(0)
                ->and($row['last_error'])->toBeNull();
        }

        expect($response->json('summary.totals.attempted'))->toBe(0);
    });

    it('surfaces the most recent failure message as last_error per event type', function (): void {
        $shop = User::factory()->create();

        [$event1, $integration] = createMetaTrackingEvent($shop, TrackingEventType::AddToCart, '2026-05-01 09:00:00');
        createMetaDelivery($event1, $integration, 'failed', '2026-05-01 09:00:05', 'Older error message');

        [$event2] = createMetaTrackingEvent($shop, TrackingEventType::AddToCart, '2026-05-01 15:00:00');
        createMetaDelivery($event2, $integration, 'partial_failure', '2026-05-01 15:00:05', 'Most recent error message');

        $response = $this->actingAs($shop)->getJson('/api/analytics?mode=single_day&date=2026-05-01&platform=meta');

        $response->assertOk();

        $row = collect($response->json('summary.delivery_stats'))->firstWhere('event', 'add_to_cart');

        expect($row['last_error'])->toBe('Most recent error message');
    });

    it('isolates delivery stats by shop: shop A cannot see shop B deliveries', function (): void {
        $shopA = User::factory()->create();
        $shopB = User::factory()->create();

        [$eventB, $integrationB] = createMetaTrackingEvent($shopB, TrackingEventType::Purchase, '2026-05-01 10:00:00');
        createMetaDelivery($eventB, $integrationB, 'delivered', '2026-05-01 10:00:05');

        $response = $this->actingAs($shopA)->getJson('/api/analytics?mode=single_day&date=2026-05-01&platform=meta');

        $response->assertOk();

        expect($response->json('summary.totals.attempted'))->toBe(0);

        $purchaseRow = collect($response->json('summary.delivery_stats'))->firstWhere('event', 'purchase');
        expect($purchaseRow['attempted'])->toBe(0)
            ->and($purchaseRow['delivered'])->toBe(0);
    });

    it('filters by tracking_event occurred_at, not platform_delivery created_at', function (): void {
        $shop = User::factory()->create();

        // Event occurred inside the requested period, but the delivery attempt
        // (created_at) landed the next day — e.g. queued near midnight and
        // processed after the period boundary. Should still be counted.
        [$eventInsidePeriod, $integration] = createMetaTrackingEvent($shop, TrackingEventType::Purchase, '2026-05-01 23:59:30');
        createMetaDelivery($eventInsidePeriod, $integration, 'delivered', '2026-05-02 00:00:10');

        // Event occurred outside the requested period, but its delivery row
        // was created_at within the period — should NOT be counted.
        [$eventOutsidePeriod] = createMetaTrackingEvent($shop, TrackingEventType::Purchase, '2026-05-02 00:00:30');
        createMetaDelivery($eventOutsidePeriod, $integration, 'delivered', '2026-05-01 23:59:50');

        $response = $this->actingAs($shop)->getJson('/api/analytics?mode=single_day&date=2026-05-01&platform=meta');

        $response->assertOk();

        $purchaseRow = collect($response->json('summary.delivery_stats'))->firstWhere('event', 'purchase');

        expect($purchaseRow['attempted'])->toBe(1)
            ->and($purchaseRow['delivered'])->toBe(1);
    });

    it('does not affect the response shape for other platforms', function (): void {
        $shop = User::factory()->create();

        TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::Purchase)->occurredAt('2026-05-01 10:00:00')->create();

        $response = $this->actingAs($shop)->getJson('/api/analytics?mode=single_day&date=2026-05-01&platform=ga4');

        $response->assertOk();

        $response->assertJsonStructure([
            'summary' => ['period', 'counts', 'total'],
        ]);

        expect($response->json('summary'))->not->toHaveKey('delivery_stats');
    });
});
