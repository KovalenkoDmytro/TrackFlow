<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the shared Google OAuth credential used for GA4 Data API
 * reporting is missing, revoked, or fails to refresh (see
 * App\Services\Ga4TokenProvider).
 */
final class GoogleOAuthException extends RuntimeException {}
