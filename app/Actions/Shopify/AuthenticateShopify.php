<?php

declare(strict_types=1);

namespace App\Actions\Shopify;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsController;
use Osiset\ShopifyApp\Actions\AuthenticateShop as VendorAuthenticateShop;
use Osiset\ShopifyApp\Exceptions\MissingAuthUrlException;
use Osiset\ShopifyApp\Http\Controllers\AuthController as VendorAuthController;
use Osiset\ShopifyApp\Util;

/**
 * Replacement for the kyon147/laravel-shopify package's built-in `/authenticate`
 * route. `App\Providers\AppServiceProvider::excludeAuthenticateFromVendorRoutes()`
 * force-adds `authenticate` to the `shopify-app.manual_routes` config during the
 * register() phase (before the vendor package's own boot() decides whether to
 * register its route), so the vendor never registers its own `/authenticate`
 * route in the first place — this Action is registered in its place in
 * routes/web.php and is the only handler for that URI.
 *
 * Why this exists: shopify.app.toml sets `[access] use_legacy_install_flow = false`
 * (Shopify managed installation), which means Shopify never sends a `code` query
 * parameter to this redirect URL — the legacy authorization-code-grant flow is
 * disabled on Shopify's side for this app. Falling through to the vendor's own
 * `AuthController@authenticate` in that state builds a legacy OAuth authorize URL
 * that Shopify rejects, producing a 500 (MissingAuthUrlException) on every fresh
 * install.
 *
 * The vendor's `AuthenticateShop`/`InstallShop` actions already fully support
 * Shopify's token-exchange flow — they accept an `id_token` and call
 * `ApiHelper::performOfflineTokenExchange()` — but nothing in the default request
 * flow ever supplies one. This Action fills that gap without touching vendor code:
 *
 * - `code` or `id_token` present in the request -> delegate to the vendor's own
 *   `AuthController@authenticate` (100% vendor install/auth logic, reused as-is).
 *   If the token exchange itself fails, the vendor throws `MissingAuthUrlException`
 *   (see `Osiset\ShopifyApp\Traits\AuthController::authenticate()`); we catch that
 *   here and show the merchant a friendly retry page instead of a bare 500.
 * - Neither present -> render a bridge page that boots Shopify App Bridge,
 *   fetches a fresh ID token client-side (`shopify.idToken()`), and resubmits
 *   this exact same URL (via POST, so the token never lands in a URL/query
 *   string) with `id_token` attached so the vendor logic above can complete
 *   the exchange on the next request.
 */
final class AuthenticateShopify
{
    use AsController;

    public function handle(
        Request $request,
        VendorAuthController $authController,
        VendorAuthenticateShop $authenticateShop,
    ): View|RedirectResponse {
        if ($request->filled('code') || $request->filled('id_token')) {
            try {
                return $authController->authenticate($request, $authenticateShop);
            } catch (MissingAuthUrlException $exception) {
                // `LoggingApiHelper` already logged the underlying exchange
                // failure with details; this is just the request-level context
                // for why the merchant is seeing a retry page.
                Log::warning('shopify.auth.token_exchange_retry_shown', [
                    'shop' => $request->string('shop')->toString(),
                    'exception' => $exception::class,
                ]);

                return $this->bridgeView($request, failed: true);
            }
        }

        return $this->bridgeView($request);
    }

    private function bridgeView(Request $request, bool $failed = false): View
    {
        return view('shopify.authenticate-bridge', [
            'apiKey' => Util::getShopifyConfig('api_key', $request->string('shop')->toString()),
            'failed' => $failed,
        ]);
    }
}
