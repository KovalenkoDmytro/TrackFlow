<?php

declare(strict_types=1);

return [
    'retention_days' => (int) env('TRACKING_RETENTION_DAYS', 90),

    // How far back to look for a click id (fbc) seen earlier for the same shop + fbp when
    // a Meta event arrives without one. Meta accepts fbc for 90 days; kept conservative.
    'fbc_lookback_days' => (int) env('TRACKING_FBC_LOOKBACK_DAYS', 7),

    'view_item_rate_limit' => [
        'per_ip_attempts' => (int) env('VIEW_ITEM_PER_IP_ATTEMPTS', 60),
        'per_ip_decay_seconds' => (int) env('VIEW_ITEM_PER_IP_DECAY_SECONDS', 300),
        'per_shop_attempts' => (int) env('VIEW_ITEM_PER_SHOP_ATTEMPTS', 300),
        'per_shop_decay_seconds' => (int) env('VIEW_ITEM_PER_SHOP_DECAY_SECONDS', 60),
    ],
];
