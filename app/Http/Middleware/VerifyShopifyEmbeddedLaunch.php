<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Shopify\ResolveShopFromSessionToken;
use App\Enums\SessionTokenFailure;
use App\Exceptions\Shopify\SessionTokenRejected;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the embedded shell routes (production only — local uses DevShopAuth).
 *
 * Replaces the vendor `verify.shopify` middleware, which is the source of the
 * live auth bypass this migration closes: it would log a shop in from the
 * `shop` query param/header/referer with no signature/token check whenever no
 * HMAC happened to be present. This middleware trusts only:
 *
 *  - an `id_token` verifiable by the same session-token logic used for
 *    `/api/*` (App\Actions\Shopify\ResolveShopFromSessionToken — reused
 *    as-is so the claim checks are never duplicated), checked FIRST and
 *    accepted alone if valid — even alongside a stale `hmac`/`timestamp`
 *    pair, since the bounce page reloads the original URL with `id_token`
 *    appended without stripping those now-expired params; requiring both
 *    to pass would bounce that reload forever — or
 *  - a Shopify query-string HMAC (verified against the query string alone —
 *    never header or referer) with a timestamp inside a 5-minute window,
 *    used only when no `id_token` is present at all.
 *
 * It never logs in (`Auth::login()`)/writes to the session — purely a
 * stateless gate — and is Octane-safe (final, no instance state).
 */
final class VerifyShopifyEmbeddedLaunch
{
    // The "D" modifier anchors "$" to the very end of the string only (not
    // before a trailing newline) — see the identical note in
    // App\Actions\Shopify\ResolveShopFromSessionToken.
    private const string SHOP_DOMAIN_PATTERN = '/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/D';

    private const int TIMESTAMP_LEEWAY_SECONDS = 300;

    /** @var list<string> */
    private const array BOUNCE_QUERY_STRIP = ['hmac', 'timestamp', 'shop', 'session', 'locale', 'id_token', 'signature'];

    public function handle(Request $request, Closure $next): Response
    {
        // id_token is checked FIRST, and accepted alone if valid, even when
        // the URL still carries a stale hmac/timestamp pair. The bounce page
        // reloads the ORIGINAL URL with id_token appended — that URL still
        // has the now-expired hmac/timestamp from the launch that triggered
        // the bounce. Verifying HMAC first (or requiring both) would reject
        // that reload and bounce again forever.
        $idToken = $request->query('id_token');

        if (is_string($idToken) && $idToken !== '') {
            try {
                app(ResolveShopFromSessionToken::class)->handle($idToken);

                return $next($request);
            } catch (SessionTokenRejected $exception) {
                if ($exception->reason() === SessionTokenFailure::ShopNotInstalled) {
                    return $this->redirectToAuthenticate($request, $exception->shopDomain());
                }

                return $this->bounce($request);
            }
        }

        $hmacShop = $this->verifyQueryHmac($request);

        if ($hmacShop !== null) {
            return $this->installedShop($hmacShop) === null
                ? $this->redirectToAuthenticate($request, $hmacShop)
                : $next($request);
        }

        return $this->bounce($request);
    }

    /**
     * Verifies the Shopify query-string HMAC per Shopify's documented
     * algorithm: remove `hmac`, sort the remaining params, and compare a
     * timing-safe HMAC-SHA256 over the urldecoded query string. Only the
     * query string is ever considered — never the `X-Shop-Signature` header
     * or `referer`, both of which the vendor middleware trusted and which
     * made the original bypass possible.
     */
    private function verifyQueryHmac(Request $request): ?string
    {
        $params = $request->query();

        $hmac = $params['hmac'] ?? null;
        $shop = $params['shop'] ?? null;
        $timestamp = $params['timestamp'] ?? null;

        if (! is_string($hmac) || ! is_string($shop) || ! is_string($timestamp)) {
            return null;
        }

        if (preg_match(self::SHOP_DOMAIN_PATTERN, $shop) !== 1) {
            return null;
        }

        if (abs(time() - (int) $timestamp) > self::TIMESTAMP_LEEWAY_SECONDS) {
            return null;
        }

        unset($params['hmac']);
        ksort($params);

        $computed = hash_hmac(
            'sha256',
            urldecode(http_build_query($params)),
            (string) config('shopify-app.api_secret'),
        );

        return hash_equals($computed, $hmac) ? $shop : null;
    }

    private function installedShop(string $shopDomain): ?User
    {
        $shop = User::query()->where('name', $shopDomain)->first();

        if ($shop === null || blank($shop->password) || $shop->hasCorruptExpiringTokenState()) {
            return null;
        }

        return $shop;
    }

    private function redirectToAuthenticate(Request $request, ?string $shopDomain): RedirectResponse
    {
        return redirect()->route('authenticate', [
            'shop' => $shopDomain ?? $request->query('shop'),
            'host' => $request->query('host'),
        ]);
    }

    /**
     * Redirects to the session-token bounce page so App Bridge can fetch a
     * fresh `id_token` and reload. The reload target is derived only from
     * this request's own relative path and a filtered copy of its query
     * string — never from any request-supplied absolute-URL value — so this
     * can never be turned into an open redirect.
     */
    private function bounce(Request $request): RedirectResponse
    {
        $target = Str::start($request->path(), '/');

        $query = collect($request->query())->except(self::BOUNCE_QUERY_STRIP)->all();

        if ($query !== []) {
            $target .= '?'.http_build_query($query);
        }

        $shopDomain = $request->query('shop');

        if (! $shopDomain && $host = $request->query('host')) {
            $decodedHost = base64_decode($host, true) ?: '';

            if (preg_match('#/store/([a-z0-9\-]+)#i', $decodedHost, $matches)) {
                $shopDomain = $matches[1].'.myshopify.com';
            }
        }

        return redirect()->route('shopify.session-token-bounce', [
            'shopify-reload' => $target,
            'host' => $request->query('host'),
            'shop' => $shopDomain,
        ]);
    }
}
