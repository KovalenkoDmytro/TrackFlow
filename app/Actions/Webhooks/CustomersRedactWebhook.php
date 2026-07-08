<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * Handles the mandatory GDPR "customers/redact" webhook.
 *
 * The app stores no shopper-identifying PII (no customer_id, email, phone,
 * name, or address anywhere in the schema — see tracking_events table).
 * There is therefore nothing per-customer to redact. The request is logged
 * for audit purposes and acknowledged with a 200 response.
 *
 * HMAC verification is handled upstream by the `auth.webhook` middleware
 * (Osiset\ShopifyApp\Http\Middleware\AuthWebhook) — this Action only runs
 * for requests with a valid Shopify signature.
 */
final class CustomersRedactWebhook
{
    use AsController;

    public function handle(Request $request): Response
    {
        // Resolve the shop from the signed JSON body (`shop_domain`), not the
        // `X-Shopify-Shop-Domain` header, since the header is not covered by
        // the HMAC verified in AuthWebhook and cannot be trusted.
        $shopDomain = $request->input('shop_domain');

        Log::info('Received customers/redact webhook', [
            'shop_domain' => $shopDomain,
        ]);

        return response('', 200);
    }
}
