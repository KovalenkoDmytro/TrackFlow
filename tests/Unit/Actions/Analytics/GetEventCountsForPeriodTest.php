<?php

declare(strict_types=1);

use App\Actions\Analytics\GetEventCountsForPeriod;
use App\Enums\TrackingEventType;
use App\Models\TrackingEvent;
use App\Models\User;
use Carbon\CarbonImmutable;

mutates(GetEventCountsForPeriod::class);

describe('GetEventCountsForPeriod', function (): void {
    beforeEach(function (): void {
        $this->shop = User::factory()->create();
        $this->action = new GetEventCountsForPeriod;
        $this->start = CarbonImmutable::parse('2026-05-01 00:00:00');
        $this->end = CarbonImmutable::parse('2026-05-01 23:59:59');
    });

    it('returns all 9 canonical event types even when tracking_events table is empty', function (): void {
        $result = $this->action->handle($this->shop, $this->start, $this->end);

        expect($result)->toHaveCount(9);

        $returnedEvents = array_column($result, 'event');
        foreach (TrackingEventType::cases() as $type) {
            expect($returnedEvents)->toContain($type->value);
        }
    });

    it('zero-fills missing event types', function (): void {
        $result = $this->action->handle($this->shop, $this->start, $this->end);

        foreach ($result as $row) {
            expect($row['count'])->toBe(0);
        }
    });

    it('counts only events within the date range', function (): void {
        TrackingEvent::factory()->forUser($this->shop)->forEvent(TrackingEventType::Purchase)->occurredAt('2026-05-01 12:00:00')->create();
        TrackingEvent::factory()->forUser($this->shop)->forEvent(TrackingEventType::Purchase)->occurredAt('2026-05-02 00:00:01')->create();

        $result = $this->action->handle($this->shop, $this->start, $this->end);

        $purchase = collect($result)->firstWhere('event', TrackingEventType::Purchase->value);
        expect($purchase['count'])->toBe(1);
    });

    it('scopes strictly to the given user_id and does not count another shop events', function (): void {
        $otherShop = User::factory()->create();

        TrackingEvent::factory()->forUser($otherShop)->forEvent(TrackingEventType::AddToCart)->occurredAt('2026-05-01 10:00:00')->create();
        TrackingEvent::factory()->forUser($otherShop)->forEvent(TrackingEventType::Purchase)->occurredAt('2026-05-01 10:00:00')->create();

        $result = $this->action->handle($this->shop, $this->start, $this->end);

        foreach ($result as $row) {
            expect($row['count'])->toBe(0);
        }
    });

    it('returns correct count when multiple events of the same type exist', function (): void {
        TrackingEvent::factory()->forUser($this->shop)->forEvent(TrackingEventType::ViewItem)->occurredAt('2026-05-01 08:00:00')->create();
        TrackingEvent::factory()->forUser($this->shop)->forEvent(TrackingEventType::ViewItem)->occurredAt('2026-05-01 09:00:00')->create();
        TrackingEvent::factory()->forUser($this->shop)->forEvent(TrackingEventType::ViewItem)->occurredAt('2026-05-01 10:00:00')->create();

        $result = $this->action->handle($this->shop, $this->start, $this->end);

        $viewItem = collect($result)->firstWhere('event', TrackingEventType::ViewItem->value);
        expect($viewItem['count'])->toBe(3);
    });

    it('includes an event that occurred exactly at the $end boundary', function (): void {
        TrackingEvent::factory()->forUser($this->shop)->forEvent(TrackingEventType::BeginCheckout)->occurredAt('2026-05-01 23:59:59')->create();

        $result = $this->action->handle($this->shop, $this->start, $this->end);

        $checkout = collect($result)->firstWhere('event', TrackingEventType::BeginCheckout->value);
        expect($checkout['count'])->toBe(1);
    });

    it('includes an event that occurred exactly at the $start boundary', function (): void {
        TrackingEvent::factory()->forUser($this->shop)->forEvent(TrackingEventType::Search)->occurredAt('2026-05-01 00:00:00')->create();

        $result = $this->action->handle($this->shop, $this->start, $this->end);

        $search = collect($result)->firstWhere('event', TrackingEventType::Search->value);
        expect($search['count'])->toBe(1);
    });

    it('each result row contains event, label, and count keys', function (): void {
        $result = $this->action->handle($this->shop, $this->start, $this->end);

        foreach ($result as $row) {
            expect($row)
                ->toHaveKey('event')
                ->toHaveKey('label')
                ->toHaveKey('count');
        }
    });
});
