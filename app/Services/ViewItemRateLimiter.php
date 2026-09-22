<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

final class ViewItemRateLimiter
{
    /**
     * Return true when a view_item event may be accepted and record the attempt.
     * Non-view_item events are never limited by this service.
     */
    public function allow(User $shop, string $event, ?string $ip): bool
    {
        if ($event !== 'view_item') {
            return true;
        }

        $config = config('tracking.view_item_rate_limit');
        $ipKey = 'tracking:view-item:ip:'.hash('sha256', $shop->getKey().'|'.($ip ?? 'unknown'));
        $shopKey = 'tracking:view-item:shop:'.$shop->getKey();

        if (RateLimiter::tooManyAttempts($ipKey, $config['per_ip_attempts'])
            || RateLimiter::tooManyAttempts($shopKey, $config['per_shop_attempts'])) {
            return false;
        }

        RateLimiter::hit($ipKey, $config['per_ip_decay_seconds']);
        RateLimiter::hit($shopKey, $config['per_shop_decay_seconds']);

        return true;
    }
}
