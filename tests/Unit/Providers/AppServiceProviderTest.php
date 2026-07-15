<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;

describe('AppServiceProvider: Shopify route safety guards', function (): void {
    it('throws when SHOPIFY_DOMAIN is configured, since routes/web.php registers /authenticate without accounting for a domain-scoped route group', function (): void {
        config(['shopify-app.domain' => 'shopify.example.com']);

        $provider = new AppServiceProvider(app());

        expect(fn () => $provider->boot())->toThrow(RuntimeException::class);
    });

    it('throws when SHOPIFY_APP_PREFIX is configured, since routes/web.php registers /authenticate without accounting for a route prefix', function (): void {
        config(['shopify-app.prefix' => 'admin']);

        $provider = new AppServiceProvider(app());

        expect(fn () => $provider->boot())->toThrow(RuntimeException::class);
    });

    it('does not throw when domain/prefix are both empty (the current default)', function (): void {
        config(['shopify-app.domain' => null, 'shopify-app.prefix' => '']);

        $provider = new AppServiceProvider(app());

        expect(fn () => $provider->boot())->not->toThrow(RuntimeException::class);
    });
});

describe('AppServiceProvider: forces "authenticate" out of the vendor Shopify routes', function (): void {
    it('adds "authenticate" to shopify-app.manual_routes even when the raw config lacks it', function (): void {
        config(['shopify-app.manual_routes' => 'home,webhook']);

        $provider = new AppServiceProvider(app());
        $provider->register();

        $manualRoutes = explode(',', (string) config('shopify-app.manual_routes'));

        expect($manualRoutes)->toContain('authenticate');
    });

    it('does not duplicate "authenticate" when it is already present', function (): void {
        config(['shopify-app.manual_routes' => 'home,webhook,authenticate']);

        $provider = new AppServiceProvider(app());
        $provider->register();

        $manualRoutes = explode(',', (string) config('shopify-app.manual_routes'));

        expect(array_count_values($manualRoutes)['authenticate'])->toBe(1);
    });
});
