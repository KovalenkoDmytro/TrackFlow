<?php

declare(strict_types=1);

return [
    // Recipient of health alerts. Empty disables alert emails (problems are still logged).
    'email' => env('ALERT_EMAIL'),

    // Optional URL pinged (HTTP GET) after a fully healthy health:check run, for
    // an external dead-man's-switch monitor that also catches a dead scheduler.
    'ping_url' => env('HEALTHCHECK_PING_URL'),

    // Minimum hours between repeated emails for the same set of problems.
    'throttle_hours' => (int) env('ALERT_THROTTLE_HOURS', 6),

    // Google Ads click sync: newest successful sync older than this is a problem.
    'google_ads_sync_max_age_hours' => (int) env('ALERT_GOOGLE_ADS_SYNC_MAX_AGE_HOURS', 3),

    // Delivery failures per integration within the last hour.
    'delivery_failure_min_count' => (int) env('ALERT_DELIVERY_FAILURE_MIN_COUNT', 5),
    'delivery_failure_min_percent' => (int) env('ALERT_DELIVERY_FAILURE_MIN_PERCENT', 50),

    // Oldest pending queued job older than this many minutes means the worker is likely down.
    'queue_backlog_max_age_minutes' => (int) env('ALERT_QUEUE_BACKLOG_MAX_AGE_MINUTES', 15),
];
