<?php

declare(strict_types=1);

namespace App\Actions\Shopify;

use App\Enums\SessionTokenFailure;
use App\Exceptions\Shopify\SessionTokenRejected;
use App\Models\User;
use Assert\AssertionFailedException;
use Lorisleiva\Actions\Concerns\AsObject;
use Osiset\ShopifyApp\Objects\Values\SessionToken as VendorSessionToken;
use Throwable;

/**
 * Verifies a Shopify session-token JWT and resolves it to the installed shop.
 *
 * Reuses the vendor `SessionToken` value object for signature verification
 * (HS256, `SHOPIFY_API_SECRET`) and standard claim decoding/expiration only —
 * never the vendor's `VerifyShopify` middleware, which is the source of the
 * live auth bypass this Action replaces on `/api/*`. Every vendor decode
 * failure (malformed token, bad signature, expired/skewed claims) is caught
 * here so a raw exception never escapes as a 500.
 *
 * On top of the vendor's own checks this additionally requires an exact
 * (not "contains") `iss`/`aud`/`dest` match and a present `sid` claim — the
 * vendor skips its issuer check entirely for tokens without `sid` (its
 * "checkout extension" token shape), which would otherwise let such tokens
 * slip through as valid app session tokens.
 */
final class ResolveShopFromSessionToken
{
    use AsObject;

    // The "D" modifier anchors "$" to the very end of the string only (not
    // before a trailing newline), so a `dest` like "https://shop.myshopify.com\n<script>"
    // can't slip past this as a match.
    private const string DESTINATION_PATTERN = '/^https:\/\/[a-z0-9][a-z0-9\-]*\.myshopify\.com$/D';

    public function handle(string $jwt): User
    {
        $token = $this->decode($jwt);
        $shopDomain = $this->verifiedShopDomain($token);

        $shop = User::query()->where('name', $shopDomain)->first();

        if ($shop === null || blank($shop->password) || $shop->hasCorruptExpiringTokenState()) {
            throw new SessionTokenRejected(SessionTokenFailure::ShopNotInstalled, $shopDomain);
        }

        return $shop;
    }

    private function decode(string $jwt): VendorSessionToken
    {
        try {
            return new VendorSessionToken($jwt);
        } catch (Throwable $e) {
            if ($e instanceof AssertionFailedException && $e->getMessage() === VendorSessionToken::EXCEPTION_EXPIRED) {
                throw new SessionTokenRejected(SessionTokenFailure::Expired);
            }

            throw new SessionTokenRejected(SessionTokenFailure::Invalid);
        }
    }

    private function verifiedShopDomain(VendorSessionToken $token): string
    {
        if ($token->getSessionId()->toNative() === '') {
            throw new SessionTokenRejected(SessionTokenFailure::Invalid);
        }

        $destination = $token->getDestination();

        if (preg_match(self::DESTINATION_PATTERN, $destination) !== 1) {
            throw new SessionTokenRejected(SessionTokenFailure::Invalid);
        }

        $shopDomain = substr($destination, strlen('https://'));

        if ($token->getIssuer() !== "https://{$shopDomain}/admin") {
            throw new SessionTokenRejected(SessionTokenFailure::Invalid);
        }

        if ($token->getAudience() !== (string) config('shopify-app.api_key')) {
            throw new SessionTokenRejected(SessionTokenFailure::Invalid);
        }

        return $shopDomain;
    }
}
