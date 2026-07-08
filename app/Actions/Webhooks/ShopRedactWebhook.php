<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsController;
use Osiset\ShopifyApp\Exceptions\InvalidShopDomainException;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;

/**
 * Handles the mandatory GDPR "shop/redact" webhook.
 *
 * Sent by Shopify ~48 hours after app/uninstalled to request final erasure
 * of all shop-scoped data. AppUninstalledJob already deletes the shop's User
 * record (and, via cascadeOnDelete foreign keys, its tracking_events,
 * platform_integrations, conversion_action_mappings, and platform_deliveries)
 * on uninstall, so in the common case the shop is already gone by the time
 * this webhook arrives. This handler is defensive: it explicitly purges the
 * shop's tracking data and then removes the shop record itself, in case the
 * uninstall cleanup did not run (e.g. job failure) or the shop was reinstalled
 * and uninstalled again before redaction completed.
 *
 * HMAC verification is handled upstream by the `auth.webhook` middleware
 * (Osiset\ShopifyApp\Http\Middleware\AuthWebhook) — this Action only runs
 * for requests with a valid Shopify signature.
 */
final class ShopRedactWebhook
{
    use AsController;

    public function handle(Request $request): Response
    {
        // Shopify's signed JSON body carries `shop_domain` for the shop/redact
        // topic. The `X-Shopify-Shop-Domain` header is NOT covered by the HMAC
        // computed in AuthWebhook (it only hashes the raw body), so it must
        // not be trusted to decide which shop's data gets erased.
        $domain = $request->input('shop_domain');
        $headerDomain = $request->header('X-Shopify-Shop-Domain');

        if (is_string($domain) && is_string($headerDomain) && $domain !== $headerDomain) {
            Log::warning('shop/redact webhook shop_domain body value disagrees with X-Shopify-Shop-Domain header', [
                'body_shop_domain' => $domain,
                'header_shop_domain' => $headerDomain,
            ]);
        }

        if (empty($domain)) {
            Log::info('Received shop/redact webhook with no shop_domain in body');

            return response('', 200);
        }

        try {
            $shopDomain = ShopDomain::fromNative($domain);
        } catch (InvalidShopDomainException $exception) {
            Log::info('Received shop/redact webhook with an invalid shop domain', [
                'shop_domain' => $domain,
                'exception' => $exception->getMessage(),
            ]);

            return response('', 200);
        }

        $shop = User::query()
            ->where('name', $shopDomain->toNative())
            ->first();

        if (! $shop instanceof User) {
            Log::info('Received shop/redact webhook for unknown/already-purged shop', [
                'shop_domain' => $shopDomain->toNative(),
            ]);

            return response('', 200);
        }

        $shop->trackingEvents()->delete();
        $shop->platformIntegrations()->delete();
        $shop->forceDelete();

        Log::info('Purged shop data for shop/redact webhook', [
            'shop_domain' => $shopDomain->toNative(),
        ]);

        return response('', 200);
    }
}
