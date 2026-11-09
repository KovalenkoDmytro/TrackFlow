<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Osiset\ShopifyApp\Http\Middleware\IframeProtection;

it('keeps the Shopify frame policy working with serialized cache entries', function (): void {
    config(['cache.default' => 'file', 'cache.serializable_classes' => false, 'shopify-app.iframe_ancestors' => null]);
    $shop = User::factory()->create(['name' => 'iframe-test.myshopify.com']);
    $key = 'frame-ancestors-domain-'.hash('sha256', $shop->name);
    Cache::forget($key);
    $middleware = app(IframeProtection::class);
    $request = Request::create('/?shop='.$shop->name);

    foreach (range(1, 2) as $attempt) {
        $response = $middleware->handle($request, fn () => response('ok'));
        expect($response->headers->get('Content-Security-Policy'))
            ->toBe('frame-ancestors https://iframe-test.myshopify.com https://admin.shopify.com');
    }

    expect(Cache::get($key))->toBe($shop->name);
    Cache::forget($key);
});
