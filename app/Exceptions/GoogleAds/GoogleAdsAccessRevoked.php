<?php

declare(strict_types=1);

namespace App\Exceptions\GoogleAds;

use RuntimeException;

/**
 * Google rejected an API call with USER_PERMISSION_DENIED: the connected Google
 * account no longer has access to the customer account (revoked by the client or
 * agency), or the account is reached through a manager account we did not send
 * as login-customer-id.
 *
 * It is a RuntimeException so any caller that does not know about it keeps its
 * existing failure handling. Callers that do know about it mark the integration
 * as needing a reconnect instead of retrying or alerting.
 */
final class GoogleAdsAccessRevoked extends RuntimeException {}
