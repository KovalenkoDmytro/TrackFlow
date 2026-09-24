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

    it('also forces out "authenticate.token" (reflected XSS in the vendor view) and the unused billing routes', function (): void {
        config(['shopify-app.manual_routes' => 'home,webhook']);

        $provider = new AppServiceProvider(app());
        $provider->register();

        $manualRoutes = explode(',', (string) config('shopify-app.manual_routes'));

        expect($manualRoutes)
            ->toContain('authenticate.token')
            ->toContain('api')
            ->toContain('billing')
            ->toContain('billing.process')
            ->toContain('billing.usage_charge');
    });
});

describe('AppServiceProvider: the vendor routes closed above are genuinely unregistered', function (): void {
    it('does not register /authenticate/token (reflected XSS in the vendor token.blade.php view)', function (): void {
        $response = $this->get('/authenticate/token?shop=nobody.myshopify.com&target=</script><script>alert(document.domain)</script>');

        $response->assertNotFound();
    });

    it('does not register the unauthenticated vendor billing routes', function (): void {
        $this->get('/billing')->assertNotFound();
        $this->get('/billing/process')->assertNotFound();
        $this->get('/billing/usage-charge')->assertNotFound();
    });
});

describe('AppServiceProvider: Shopify credential safety guard', function (): void {
    it('throws when SHOPIFY_API_SECRET is blank outside the testing environment', function (): void {
        app()->detectEnvironment(fn () => 'production');
        config(['shopify-app.api_secret' => '']);

        $provider = new AppServiceProvider(app());

        expect(fn () => $provider->boot())->toThrow(RuntimeException::class);

        app()->detectEnvironment(fn () => 'testing');
    });

    it('throws when SHOPIFY_API_KEY is blank outside the testing environment', function (): void {
        app()->detectEnvironment(fn () => 'production');
        config(['shopify-app.api_key' => '']);

        $provider = new AppServiceProvider(app());

        expect(fn () => $provider->boot())->toThrow(RuntimeException::class);

        app()->detectEnvironment(fn () => 'testing');
    });

    it('does not throw when both are blank inside the testing environment', function (): void {
        config(['shopify-app.api_key' => '', 'shopify-app.api_secret' => '']);

        $provider = new AppServiceProvider(app());

        expect(fn () => $provider->boot())->not->toThrow(RuntimeException::class);
    });
});
