<?php

declare(strict_types=1);

return [
    'retention_days' => (int) env('TRACKING_RETENTION_DAYS', 90),

    // How far back to look for a click id (fbc) seen earlier for the same shop + fbp when
    // a Meta event arrives without one. Meta accepts fbc for 90 days; kept conservative.
    'fbc_lookback_days' => (int) env('TRACKING_FBC_LOOKBACK_DAYS', 7),

    // Google Ads rejects click conversions older than the conversion action's click-through
    // lookback window (EXPIRED_EVENT). Used when the real window cannot be read from the API.
    'google_ads' => [
        'default_click_window_days' => (int) env('TRACKING_GOOGLE_ADS_DEFAULT_CLICK_WINDOW_DAYS', 30),
        // Events whose age is within this many days of the window edge are not uploaded.
        'safety_margin_days' => (int) env('TRACKING_GOOGLE_ADS_SAFETY_MARGIN_DAYS', 5),
        // Conversion times are capped at now minus this many seconds (browser clocks can run ahead
        // of Google's, which rejects future times with LATER_THAN_MAXIMUM_DATE).
        'future_clamp_seconds' => (int) env('TRACKING_GOOGLE_ADS_FUTURE_CLAMP_SECONDS', 60),
    ],

    'view_item_rate_limit' => [
        'per_ip_attempts' => (int) env('VIEW_ITEM_PER_IP_ATTEMPTS', 60),
        'per_ip_decay_seconds' => (int) env('VIEW_ITEM_PER_IP_DECAY_SECONDS', 300),
        'per_shop_attempts' => (int) env('VIEW_ITEM_PER_SHOP_ATTEMPTS', 300),
        'per_shop_decay_seconds' => (int) env('VIEW_ITEM_PER_SHOP_DECAY_SECONDS', 60),
    ],
];
