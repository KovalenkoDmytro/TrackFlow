<?php

declare(strict_types=1);

namespace App\Enums;

use App\Actions\Shopify\MigrateOfflineToken;

/**
 * Outcome of a single-shop legacy-to-expiring offline token migration attempt.
 *
 * @see MigrateOfflineToken
 */
enum OfflineTokenMigrationResult: string
{
    /** The shop already has expiring offline tokens — no API call was made. */
    case AlreadyMigrated = 'already_migrated';

    /** The token exchange succeeded and all four fields were persisted. */
    case Success = 'success';

    /**
     * The exchange failed but the legacy offline token is still alive on
     * Shopify's side — safe to retry later, no state was mutated.
     */
    case TransientFailure = 'transient_failure';

    /**
     * The exchange failed and the legacy offline token is now dead — Shopify
     * likely completed the exchange server-side but we never captured the
     * result. Flagged for alerting; requires manual/automated recovery.
     */
    case Orphaned = 'orphaned';
}
