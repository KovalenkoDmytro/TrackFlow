<?php

declare(strict_types=1);

use App\Models\TrackingEvent;
use App\Models\User;

it('deletes tracking events older than the retention period', function (): void {
    $shop = User::factory()->create();
    $old = TrackingEvent::factory()->forUser($shop)->create([
        'created_at' => now()->subDays(91),
        'updated_at' => now()->subDays(91),
    ]);
    $recent = TrackingEvent::factory()->forUser($shop)->create([
        'created_at' => now()->subDays(89),
        'updated_at' => now()->subDays(89),
    ]);

    $this->artisan('tracking:prune --days=90')->assertSuccessful();

    expect(TrackingEvent::query()->find($old->getKey()))->toBeNull()
        ->and(TrackingEvent::query()->find($recent->getKey()))->not->toBeNull();
});

it('supports a dry run without deleting eligible events', function (): void {
    $event = TrackingEvent::factory()->create([
        'created_at' => now()->subDays(120),
        'updated_at' => now()->subDays(120),
    ]);

    $this->artisan('tracking:prune --days=90 --dry-run')
        ->expectsOutputToContain('Eligible events: 1')
        ->assertSuccessful();

    expect(TrackingEvent::query()->find($event->getKey()))->not->toBeNull();
});

it('fails validation and deletes nothing when --days is less than 1', function (): void {
    $event = TrackingEvent::factory()->create([
        'created_at' => now()->subDays(120),
        'updated_at' => now()->subDays(120),
    ]);

    $this->artisan('tracking:prune --days=0')->assertFailed();

    expect(TrackingEvent::query()->find($event->getKey()))->not->toBeNull();
});

it('fails validation and deletes nothing when --chunk is outside 1..50000', function (): void {
    $event = TrackingEvent::factory()->create([
        'created_at' => now()->subDays(120),
        'updated_at' => now()->subDays(120),
    ]);

    $this->artisan('tracking:prune --days=90 --chunk=50001')->assertFailed();

    expect(TrackingEvent::query()->find($event->getKey()))->not->toBeNull();
});

it('deletes eligible events across multiple chunk batches', function (): void {
    $shop = User::factory()->create();
    $old = TrackingEvent::factory()->forUser($shop)->count(5)->create([
        'created_at' => now()->subDays(91),
        'updated_at' => now()->subDays(91),
    ]);
    $recent = TrackingEvent::factory()->forUser($shop)->create([
        'created_at' => now()->subDays(89),
        'updated_at' => now()->subDays(89),
    ]);

    $this->artisan('tracking:prune --days=90 --chunk=2')
        ->expectsOutputToContain('Eligible events: 5')
        ->expectsOutputToContain('Deleted events: 5')
        ->assertSuccessful();

    expect(TrackingEvent::query()->whereKey($old->pluck('id'))->count())->toBe(0)
        ->and(TrackingEvent::query()->find($recent->getKey()))->not->toBeNull();
});
