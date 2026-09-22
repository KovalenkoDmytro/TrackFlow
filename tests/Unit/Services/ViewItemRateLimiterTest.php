<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\ViewItemRateLimiter;

it('limits repeated view item events by shop and IP', function (): void {
    config()->set('tracking.view_item_rate_limit', [
        'per_ip_attempts' => 2,
        'per_ip_decay_seconds' => 300,
        'per_shop_attempts' => 100,
        'per_shop_decay_seconds' => 60,
    ]);

    $shop = User::factory()->create();
    $limiter = new ViewItemRateLimiter;

    expect($limiter->allow($shop, 'view_item', '203.0.113.10'))->toBeTrue()
        ->and($limiter->allow($shop, 'view_item', '203.0.113.10'))->toBeTrue()
        ->and($limiter->allow($shop, 'view_item', '203.0.113.10'))->toBeFalse()
        ->and($limiter->allow($shop, 'view_item', '203.0.113.11'))->toBeTrue();
});

it('does not limit conversion funnel events', function (): void {
    config()->set('tracking.view_item_rate_limit.per_ip_attempts', 1);
    $shop = User::factory()->create();
    $limiter = new ViewItemRateLimiter;

    expect($limiter->allow($shop, 'purchase', '203.0.113.10'))->toBeTrue()
        ->and($limiter->allow($shop, 'purchase', '203.0.113.10'))->toBeTrue();
});

it('limits per-shop regardless of the requesting IP', function (): void {
    config()->set('tracking.view_item_rate_limit', [
        'per_ip_attempts' => 100,
        'per_ip_decay_seconds' => 300,
        'per_shop_attempts' => 2,
        'per_shop_decay_seconds' => 60,
    ]);

    $shop = User::factory()->create();
    $limiter = new ViewItemRateLimiter;

    expect($limiter->allow($shop, 'view_item', '203.0.113.10'))->toBeTrue()
        ->and($limiter->allow($shop, 'view_item', '203.0.113.11'))->toBeTrue()
        ->and($limiter->allow($shop, 'view_item', '203.0.113.12'))->toBeFalse();
});

it('buckets requests with a null IP under the shared "unknown" key', function (): void {
    config()->set('tracking.view_item_rate_limit', [
        'per_ip_attempts' => 1,
        'per_ip_decay_seconds' => 300,
        'per_shop_attempts' => 100,
        'per_shop_decay_seconds' => 60,
    ]);

    $shop = User::factory()->create();
    $limiter = new ViewItemRateLimiter;

    expect($limiter->allow($shop, 'view_item', null))->toBeTrue()
        ->and($limiter->allow($shop, 'view_item', null))->toBeFalse();
});
