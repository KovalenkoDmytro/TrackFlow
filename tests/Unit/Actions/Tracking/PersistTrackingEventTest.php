<?php

declare(strict_types=1);

use App\Actions\Tracking\PersistTrackingEvent;
use App\Data\TrackingEventData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('truncates long user agents instead of dropping the tracking event', function (): void {
    $shop = User::factory()->create();
    $data = new TrackingEventData(
        shopDomain: $shop->name,
        event: 'view_item',
        value: 10,
        currency: 'CAD',
        transactionId: null,
        gclid: null,
        fbp: null,
        fbc: null,
        ttclid: null,
        gaClientId: null,
        ip: '127.0.0.1',
        userAgent: str_repeat('a', 400),
        idempotencyKey: 'long-user-agent',
        occurredAt: new DateTimeImmutable('2026-07-31T12:00:00+00:00'),
    );

    $event = PersistTrackingEvent::make()->handle($data, $shop);

    expect($event->user_agent)->toHaveLength(255);
});
