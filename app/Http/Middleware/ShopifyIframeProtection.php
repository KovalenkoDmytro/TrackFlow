<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Osiset\ShopifyApp\Http\Middleware\IframeProtection;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Osiset\ShopifyApp\Util;

/** Cache only a domain string: Laravel's cache forbids unserializing models. */
final class ShopifyIframeProtection extends IframeProtection
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $shopDomain = ShopDomain::fromRequest($request);
        $domain = Cache::remember(
            'frame-ancestors-domain-'.hash('sha256', (string) $shopDomain->toNative()),
            now()->addMinutes(20),
            fn () => $this->shopQuery->getByDomain($shopDomain)?->name,
        ) ?? '*.myshopify.com';

        $ancestors = Util::getShopifyConfig('iframe_ancestors');
        $policy = "frame-ancestors https://{$domain} https://admin.shopify.com";
        if (! blank($ancestors)) {
            $policy .= ' '.$ancestors;
        }

        $response->headers->set('Content-Security-Policy', $policy);

        return $response;
    }
}
