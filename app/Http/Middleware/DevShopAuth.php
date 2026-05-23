<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Local-only middleware that authenticates requests as the dev shop.
 *
 * Allows testing authenticated routes (settings, home) without a real
 * Shopify OAuth session. Never registered in production — only used
 * inside the `if (app()->isLocal())` block in routes/web.php.
 */
final class DevShopAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Log in as the dev shop when it exists.  On a fresh checkout the
        // table is empty, so we continue as a guest rather than crashing.
        $shop = User::query()->first();

        if ($shop !== null) {
            auth()->login($shop);
        }

        return $next($request);
    }
}
