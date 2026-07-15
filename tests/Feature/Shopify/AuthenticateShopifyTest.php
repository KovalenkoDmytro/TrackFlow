<?php

declare(strict_types=1);

use App\Actions\Shopify\AuthenticateShopify;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Osiset\ShopifyApp\Actions\AuthenticateShop as VendorAuthenticateShop;
use Osiset\ShopifyApp\Exceptions\MissingAuthUrlException;
use Osiset\ShopifyApp\Http\Controllers\AuthController as VendorAuthController;
use Osiset\ShopifyApp\Util;

describe('AuthenticateShopify: delegates to the vendor AuthController', function (): void {
    it('delegates to VendorAuthController::authenticate when the request carries a `code` parameter', function (): void {
        $vendorAuthController = Mockery::mock(VendorAuthController::class);
        $vendorAuthenticateShop = Mockery::mock(VendorAuthenticateShop::class);
        $expected = new RedirectResponse('/home');

        $request = Request::create('/authenticate', 'GET', [
            'shop' => 'test-shop.myshopify.com',
            'code' => 'legacy-oauth-code',
        ]);

        $vendorAuthController->shouldReceive('authenticate')
            ->once()
            ->with($request, $vendorAuthenticateShop)
            ->andReturn($expected);

        $action = new AuthenticateShopify;
        $result = $action->handle($request, $vendorAuthController, $vendorAuthenticateShop);

        expect($result)->toBe($expected);
    });

    it('delegates to VendorAuthController::authenticate when the request carries an `id_token` parameter', function (): void {
        $vendorAuthController = Mockery::mock(VendorAuthController::class);
        $vendorAuthenticateShop = Mockery::mock(VendorAuthenticateShop::class);
        $expected = new RedirectResponse('/home');

        $request = Request::create('/authenticate', 'GET', [
            'shop' => 'test-shop.myshopify.com',
            'id_token' => 'fresh-session-id-token',
        ]);

        $vendorAuthController->shouldReceive('authenticate')
            ->once()
            ->with($request, $vendorAuthenticateShop)
            ->andReturn($expected);

        $action = new AuthenticateShopify;
        $result = $action->handle($request, $vendorAuthController, $vendorAuthenticateShop);

        expect($result)->toBe($expected);
    });

    it('prefers delegation when both `code` and `id_token` are present', function (): void {
        $vendorAuthController = Mockery::mock(VendorAuthController::class);
        $vendorAuthenticateShop = Mockery::mock(VendorAuthenticateShop::class);
        $expected = new RedirectResponse('/home');

        $request = Request::create('/authenticate', 'GET', [
            'shop' => 'test-shop.myshopify.com',
            'code' => 'legacy-oauth-code',
            'id_token' => 'fresh-session-id-token',
        ]);

        $vendorAuthController->shouldReceive('authenticate')
            ->once()
            ->with($request, $vendorAuthenticateShop)
            ->andReturn($expected);

        $action = new AuthenticateShopify;
        $result = $action->handle($request, $vendorAuthController, $vendorAuthenticateShop);

        expect($result)->toBe($expected);
    });
});

describe('AuthenticateShopify: renders the App Bridge bridge page', function (): void {
    it('renders the bridge view (not the vendor controller) when neither `code` nor `id_token` is present', function (): void {
        $vendorAuthController = Mockery::mock(VendorAuthController::class);
        $vendorAuthenticateShop = Mockery::mock(VendorAuthenticateShop::class);
        $vendorAuthController->shouldNotReceive('authenticate');

        $request = Request::create('/authenticate', 'GET', [
            'shop' => 'test-shop.myshopify.com',
        ]);

        $action = new AuthenticateShopify;
        $result = $action->handle($request, $vendorAuthController, $vendorAuthenticateShop);

        expect($result->name())->toBe('shopify.authenticate-bridge')
            ->and($result->getData())->toHaveKey('apiKey')
            ->and($result->getData()['apiKey'])->toBe(Util::getShopifyConfig('api_key', 'test-shop.myshopify.com'))
            ->and($result->getData()['failed'])->toBeFalse();
    });

    it('treats blank `code`/`id_token` query values the same as absent parameters', function (): void {
        $vendorAuthController = Mockery::mock(VendorAuthController::class);
        $vendorAuthenticateShop = Mockery::mock(VendorAuthenticateShop::class);
        $vendorAuthController->shouldNotReceive('authenticate');

        $request = Request::create('/authenticate', 'GET', [
            'shop' => 'test-shop.myshopify.com',
            'code' => '',
            'id_token' => '',
        ]);

        $action = new AuthenticateShopify;
        $result = $action->handle($request, $vendorAuthController, $vendorAuthenticateShop);

        expect($result->name())->toBe('shopify.authenticate-bridge');
    });
});

describe('AuthenticateShopify: graceful fallback when the vendor token exchange fails', function (): void {
    it('catches MissingAuthUrlException from the vendor controller and renders a friendly retry page instead of letting it 500', function (): void {
        $vendorAuthController = Mockery::mock(VendorAuthController::class);
        $vendorAuthenticateShop = Mockery::mock(VendorAuthenticateShop::class);

        $request = Request::create('/authenticate', 'POST', [
            'shop' => 'test-shop.myshopify.com',
            'id_token' => 'fresh-session-id-token',
        ]);

        $vendorAuthController->shouldReceive('authenticate')
            ->once()
            ->with($request, $vendorAuthenticateShop)
            ->andThrow(new MissingAuthUrlException('Missing auth url'));

        Log::shouldReceive('warning')
            ->once()
            ->with('shopify.auth.token_exchange_retry_shown', Mockery::on(
                fn (array $context): bool => $context['shop'] === 'test-shop.myshopify.com'
                    && $context['exception'] === MissingAuthUrlException::class,
            ));

        $action = new AuthenticateShopify;
        $result = $action->handle($request, $vendorAuthController, $vendorAuthenticateShop);

        expect($result->name())->toBe('shopify.authenticate-bridge')
            ->and($result->getData()['failed'])->toBeTrue();
    });

    it('lets any other exception from the vendor controller propagate unchanged', function (): void {
        $vendorAuthController = Mockery::mock(VendorAuthController::class);
        $vendorAuthenticateShop = Mockery::mock(VendorAuthenticateShop::class);

        $request = Request::create('/authenticate', 'POST', [
            'shop' => 'test-shop.myshopify.com',
            'id_token' => 'fresh-session-id-token',
        ]);

        $vendorAuthController->shouldReceive('authenticate')
            ->once()
            ->with($request, $vendorAuthenticateShop)
            ->andThrow(new RuntimeException('unrelated failure'));

        $action = new AuthenticateShopify;

        expect(fn () => $action->handle($request, $vendorAuthController, $vendorAuthenticateShop))
            ->toThrow(RuntimeException::class, 'unrelated failure');
    });
});

describe('AuthenticateShopify: route registration', function (): void {
    it('registers `/authenticate` (GET and POST) as the app AuthenticateShopify action, not the vendor route', function (): void {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($route) => $route->uri() === 'authenticate');

        expect($route)->not->toBeNull()
            ->and($route->methods())->toContain('GET')
            ->and($route->methods())->toContain('POST')
            ->and($route->getActionName())->toContain(AuthenticateShopify::class)
            ->and($route->getName())->toBe('authenticate');
    });

    it('only registers a single `/authenticate` route (the vendor package does not also register one)', function (): void {
        $matchingRoutes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => $route->uri() === 'authenticate');

        expect($matchingRoutes)->toHaveCount(1);
    });

    it('would skip the vendor package /authenticate route given the SHOPIFY_MANUAL_ROUTES documented in .env.example', function (): void {
        // Read the value directly from the committed .env.example, rather than
        // the live `shopify-app.manual_routes` config, so this assertion does
        // not depend on the ambient .env of whichever machine runs the suite
        // (and instead pins down the documented/intended fix).
        $envExample = file_get_contents(base_path('.env.example'));

        preg_match('/^SHOPIFY_MANUAL_ROUTES=(.*)$/m', (string) $envExample, $matches);

        expect($matches)->toHaveKey(1);

        $manualRoutes = explode(',', trim($matches[1]));

        expect($manualRoutes)->toContain('authenticate')
            ->and(Util::registerPackageRoute('authenticate', $manualRoutes))
            ->toBeFalse();
    });

    it('excludes "authenticate" from the live shopify-app.manual_routes config regardless of the raw env value, so the vendor route is genuinely skipped rather than merely overwritten by load order', function (): void {
        // This is the regression this test guards against: SHOPIFY_MANUAL_ROUTES
        // is NOT guaranteed to be set correctly in every environment (an
        // operator's .env can omit "authenticate" entirely). Without
        // App\Providers\AppServiceProvider::excludeAuthenticateFromVendorRoutes()
        // forcing it into the config at register() time, the vendor's own
        // `Util::registerPackageRoute('authenticate', ...)` check would return
        // true, the vendor route WOULD get registered, and our route in
        // routes/web.php would only "win" by silently overwriting it in the
        // RouteCollection because both share the same methods|domain|uri key.
        $manualRoutes = explode(',', (string) config('shopify-app.manual_routes'));

        expect($manualRoutes)->toContain('authenticate')
            ->and(Util::registerPackageRoute('authenticate', $manualRoutes))
            ->toBeFalse();
    });
});
