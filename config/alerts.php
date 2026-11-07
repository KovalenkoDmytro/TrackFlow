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

    // Consecutive clean health:check runs required before the "recovered" email is sent.
    'recovery_runs' => (int) env('ALERT_RECOVERY_RUNS', 3),

    // Active integration with at least this many delivery rows in 24h and none delivered.
    'dead_integration_min_deliveries' => (int) env('ALERT_DEAD_INTEGRATION_MIN_DELIVERIES', 10),

    // Synced click-report days needed before "no click IDs match this account" is stated as fact.
    'click_ownership_min_synced_days' => (int) env('ALERT_CLICK_OWNERSHIP_MIN_SYNCED_DAYS', 7),

    // Delivery failures (retries exhausted; partial_failure excluded) per integration within the last hour.
    'delivery_failure_min_count' => (int) env('ALERT_DELIVERY_FAILURE_MIN_COUNT', 5),
    'delivery_failure_min_percent' => (int) env('ALERT_DELIVERY_FAILURE_MIN_PERCENT', 50),

    // Oldest pending queued job older than this many minutes means the worker is likely down.
    'queue_backlog_max_age_minutes' => (int) env('ALERT_QUEUE_BACKLOG_MAX_AGE_MINUTES', 15),

    // platform_deliveries still `queued` and untouched for this many minutes (any platform) mean the
    // job died mid-attempt. The retry chain takes ~7 min, so 60 leaves wide margin. Alerts at >= min count.
    'stuck_delivery_max_age_minutes' => (int) env('ALERT_STUCK_DELIVERY_MAX_AGE_MINUTES', 60),
    'stuck_delivery_min_count' => (int) env('ALERT_STUCK_DELIVERY_MIN_COUNT', 1),

    // Plain-language hints for delivery rejection codes, shown in the dead-integration alert.
    // `alert` false marks normal data conditions (old/fresh/duplicate events): they only
    // alert when EVERY delivery in the window has such a code and is never worth reconnecting for.
    'delivery_reason_hints' => [
        'INVALID_CUSTOMER_FOR_CLICK' => ['alert' => true, 'hint' => 'the connected Google Ads account is not the one that owns the ad clicks (wrong account, manager account, or cross-account conversion tracking). Reconnect with the correct account.'],
        'CLICK_NOT_FOUND' => ['alert' => true, 'hint' => 'Google could not find the click for the gclid; check that the connected account owns the clicks and that gclids are captured intact.'],
        'INVALID_CONVERSION_ACTION' => ['alert' => true, 'hint' => 'the conversion action no longer exists or is not usable for uploads; re-run conversion action setup or reconnect.'],
        'TOO_RECENT_CONVERSION_ACTION' => ['alert' => true, 'hint' => 'the conversion action was created very recently; this is temporary and should clear within about 6 hours.'],
        'EXPIRED_EVENT' => ['alert' => false, 'hint' => 'the click is older than the allowed upload window (normal for late events).'],
        'TOO_RECENT_EVENT' => ['alert' => false, 'hint' => 'the click is too recent for Google to process (normal, self-resolving).'],
        'LATER_THAN_MAXIMUM_DATE' => ['alert' => false, 'hint' => "Event time was ahead of Google's clock (typically the shopper's browser clock running fast). Newer uploads clamp the time, so this should not recur."],
        'CLICK_CONVERSION_ALREADY_EXISTS' => ['alert' => false, 'hint' => 'this conversion was already uploaded (duplicate).'],
        'ORDER_ID_ALREADY_IN_USE' => ['alert' => false, 'hint' => 'this order id was already used for an upload (duplicate).'],
    ],
];
