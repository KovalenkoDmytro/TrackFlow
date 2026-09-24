<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Shopify\ResolveShopFromSessionToken;
use App\Enums\SessionTokenFailure;
use App\Exceptions\Shopify\SessionTokenRejected;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stateless bearer-token authentication for `/api/*`. Replaces `auth:web`
 * (session-cookie guard) so an authenticated request must present a fresh,
 * verified Shopify session token on every call — no session, no cookies, and
 * no shortcut for a guard user already set from elsewhere.
 *
 * Final and stateless (no mutable instance properties) so it is safe to
 * reuse across requests on the same Octane worker.
 */
final class AuthenticateShopifySessionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $shop = $this->resolveShop($request);
        } catch (SessionTokenRejected $exception) {
            return $this->rejectionResponse($exception->reason());
        }

        Auth::guard('web')->setUser($shop);

        return $next($request);
    }

    private function resolveShop(Request $request): User
    {
        if ($this->devBypassActive($request)) {
            return $this->resolveDevBypassShop();
        }

        $jwt = $request->bearerToken();

        if ($jwt === null || $jwt === '') {
            throw new SessionTokenRejected(SessionTokenFailure::Missing);
        }

        return app(ResolveShopFromSessionToken::class)->handle($jwt);
    }

    /**
     * Local-only escape hatch so `/api/*` can be exercised without a real
     * Shopify session token. Never active outside `local`, and a request
     * carrying any bearer token always goes through full verification
     * instead — even locally — so tunneled testing exercises the real path.
     */
    private function devBypassActive(Request $request): bool
    {
        return app()->environment('local')
            && config('shopify-app.dev_auth_bypass') === true
            && $request->bearerToken() === null;
    }

    private function resolveDevBypassShop(): User
    {
        $shop = User::query()
            ->where('name', config('shopify-app.dev_shop_domain'))
            ->first();

        if ($shop === null) {
            throw new SessionTokenRejected(SessionTokenFailure::ShopNotInstalled);
        }

        return $shop;
    }

    private function rejectionResponse(SessionTokenFailure $failure): JsonResponse
    {
        $response = response()->json([
            'message' => "Shopify session token rejected: {$failure->code()}.",
            'code' => $failure->code(),
        ], 401);

        if ($failure->shouldRetry()) {
            $response->headers->set('X-Shopify-Retry-Invalid-Session-Request', '1');
        }

        return $response;
    }
}
