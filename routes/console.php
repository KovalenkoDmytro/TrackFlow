<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Shopify expiring offline token migration — daily orphan detection/alerting.
// The bulk migration itself (shopify:migrate-offline-tokens) is run manually
// by an operator (canary rollout), not scheduled.
Schedule::command('shopify:detect-orphaned-shops')->daily();
