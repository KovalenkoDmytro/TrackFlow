<?php

declare(strict_types=1);

namespace App\Exceptions\GoogleAds;

use RuntimeException;

/**
 * Thrown by GoogleAdsClickSync when the per-integration lock is already held.
 *
 * The hourly (--days=3) and daily (--days=90) schedules both sync the same
 * integration and can legitimately overlap — this is an expected scheduling
 * clash, not a Google Ads access/auth problem, so SyncGoogleAdsClicks catches
 * it separately and reports it as a skip rather than a failure.
 */
final class SyncAlreadyRunning extends RuntimeException {}
