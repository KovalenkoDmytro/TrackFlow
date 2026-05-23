<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Models\TrackingEvent;
use App\Models\User;

describe('GET /api/analytics', function (): void {
    it('returns 401 or 302 for unauthenticated requests', function (): void {
        $response = $this->getJson('/api/analytics');

        expect($response->status())->toBeIn([401, 302]);
    });

    it('returns 200 with today data when authenticated and no params provided', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->getJson('/api/analytics');

        $response->assertOk();

        $response->assertJsonStructure([
            'filters' => ['mode', 'date', 'start_date', 'end_date'],
            'summary' => [
                'period' => ['start', 'end', 'label', 'days'],
                'counts',
                'total',
            ],
            'meta' => ['available_events', 'max_range_days', 'today'],
        ]);

        expect($response->json('filters.mode'))->toBe('single_day');
        expect($response->json('summary.counts'))->toHaveCount(9);
    });

    it('returns correct structure for mode=single_day with a specific date', function (): void {
        $shop = User::factory()->create();

        TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::Purchase)->occurredAt('2026-05-01 10:00:00')->create();

        $response = $this->actingAs($shop)->getJson('/api/analytics?mode=single_day&date=2026-05-01');

        $response->assertOk();

        expect($response->json('filters.mode'))->toBe('single_day');
        expect($response->json('filters.date'))->toBe('2026-05-01');
        expect($response->json('filters.start_date'))->toBeNull();
        expect($response->json('filters.end_date'))->toBeNull();
        expect($response->json('summary.counts'))->toHaveCount(9);
        expect($response->json('summary.period.start'))->toBe('2026-05-01');
        expect($response->json('summary.period.end'))->toBe('2026-05-01');
        expect($response->json('summary.period.days'))->toBe(1);

        $purchase = collect($response->json('summary.counts'))->firstWhere('event', 'purchase');
        expect($purchase['count'])->toBe(1);
        expect($response->json('summary.total'))->toBe(1);
    });

    it('returns correct structure for mode=range with start_date and end_date', function (): void {
        $shop = User::factory()->create();

        TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::AddToCart)->occurredAt('2026-05-03 14:00:00')->create();
        TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::ViewItem)->occurredAt('2026-05-05 09:00:00')->create();

        $response = $this->actingAs($shop)->getJson('/api/analytics?mode=range&start_date=2026-05-01&end_date=2026-05-07');

        $response->assertOk();

        expect($response->json('filters.mode'))->toBe('range');
        expect($response->json('filters.start_date'))->toBe('2026-05-01');
        expect($response->json('filters.end_date'))->toBe('2026-05-07');
        expect($response->json('filters.date'))->toBeNull();
        expect($response->json('summary.counts'))->toHaveCount(9);
        expect($response->json('summary.period.start'))->toBe('2026-05-01');
        expect($response->json('summary.period.end'))->toBe('2026-05-07');
        expect($response->json('summary.period.days'))->toBe(7);
        expect($response->json('summary.total'))->toBe(2);
    });

    it('returns 422 when end_date is before start_date', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->getJson('/api/analytics?mode=range&start_date=2026-05-10&end_date=2026-05-01');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['end_date']);
    });

    it('isolates events by shop: authenticated as shop A cannot see shop B events', function (): void {
        $shopA = User::factory()->create();
        $shopB = User::factory()->create();

        TrackingEvent::factory()->forUser($shopB)->forEvent(TrackingEventType::Purchase)->occurredAt('2026-05-01 12:00:00')->create();
        TrackingEvent::factory()->forUser($shopB)->forEvent(TrackingEventType::AddToCart)->occurredAt('2026-05-01 13:00:00')->create();

        $response = $this->actingAs($shopA)->getJson('/api/analytics?mode=single_day&date=2026-05-01');

        $response->assertOk();

        expect($response->json('summary.total'))->toBe(0);

        foreach ($response->json('summary.counts') as $row) {
            expect($row['count'])->toBe(0);
        }
    });

    it('response summary.counts contains all 9 canonical event types', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->getJson('/api/analytics?mode=single_day&date=2026-05-01');

        $response->assertOk();

        $returnedEvents = array_column($response->json('summary.counts'), 'event');
        foreach (TrackingEventType::cases() as $type) {
            expect($returnedEvents)->toContain($type->value);
        }
    });

    it('meta contains available_events with all 9 types and correct max_range_days', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->getJson('/api/analytics');

        $response->assertOk();

        expect($response->json('meta.max_range_days'))->toBe(366);
        expect($response->json('meta.available_events'))->toHaveCount(9);
    });
});
