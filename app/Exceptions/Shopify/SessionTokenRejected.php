<?php

declare(strict_types=1);

namespace App\Exceptions\Shopify;

use App\Enums\SessionTokenFailure;
use RuntimeException;

/**
 * Thrown by App\Actions\Shopify\ResolveShopFromSessionToken for any bearer
 * session-token rejection. Deliberately has no render() — every call site
 * (AuthenticateShopifySessionToken, VerifyShopifyEmbeddedLaunch) reacts to a
 * rejection differently (JSON 401 vs. redirect), so each catches this
 * explicitly rather than letting it bubble to the global exception handler.
 */
final class SessionTokenRejected extends RuntimeException
{
    public function __construct(
        private readonly SessionTokenFailure $reason,
        private readonly ?string $shopDomain = null,
    ) {
        parent::__construct("Shopify session token rejected: {$reason->code()}");
    }

    public function reason(): SessionTokenFailure
    {
        return $this->reason;
    }

    /**
     * The shop domain derived from the token, when the rejection happened
     * after claim verification succeeded (currently only ShopNotInstalled).
     * Lets callers like VerifyShopifyEmbeddedLaunch redirect to the install
     * flow without re-decoding the token themselves.
     */
    public function shopDomain(): ?string
    {
        return $this->shopDomain;
    }
}
