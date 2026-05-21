<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Authenticates an inbound conversion tracking request.
 *
 * Responsible for two concerns that belong together:
 *  1. Resolving a shop record by its myshopify domain.
 *  2. Verifying the per-shop tracking_secret against the value sent in the request.
 *
 * Separated from ConversionController so the same logic can be reused in tests
 * or alternative entry points without duplicating it.
 */
final class ConversionAuthenticator
{
    /**
     * Resolve and authenticate the shop for an inbound conversion tracking request.
     *
     * @param string $shopDomain     The myshopify domain sent in the request body.
     * @param string $trackingSecret The raw secret sent in the request body.
     *
     * @return User The authenticated shop record.
     *
     * @throws NotFoundHttpException     When no shop with the given domain exists.
     * @throws UnauthorizedHttpException When the tracking secret does not match.
     */
    public function authenticate(string $shopDomain, string $trackingSecret): User
    {
        $shop = User::query()
            ->where('name', $shopDomain)
            ->first();

        if (! $shop instanceof User) {
            throw new NotFoundHttpException('Shop not found.');
        }

        if (! $this->isValidSecret($shop, $trackingSecret)) {
            throw new UnauthorizedHttpException('', 'Invalid tracking secret.');
        }

        return $shop;
    }

    /**
     * Compare the provided secret against the stored one using a timing-safe comparison.
     *
     * hash_equals() prevents timing attacks where an attacker could infer
     * the correct secret by measuring response time differences.
     */
    private function isValidSecret(User $shop, string $providedSecret): bool
    {
        $storedSecret = $shop->tracking_secret;

        if ($storedSecret === null) {
            return false;
        }

        return hash_equals($storedSecret, $providedSecret);
    }
}
