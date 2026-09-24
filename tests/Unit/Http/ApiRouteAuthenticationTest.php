<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateShopifySessionToken;
use Illuminate\Support\Facades\Route;

describe('Architecture: /api/* authentication', function (): void {
    it('guards every /api/* route (except /api/conversions) with the session-token middleware and no session auth guard', function (): void {
        $apiRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->reject(fn ($route) => $route->uri() === 'api/conversions');

        expect($apiRoutes)->not->toBeEmpty();

        foreach ($apiRoutes as $route) {
            $middleware = $route->gatherMiddleware();

            expect($middleware)->toContain(AuthenticateShopifySessionToken::class);

            $authGuardMiddleware = collect($middleware)
                ->filter(fn (string $name) => $name === 'auth' || str_starts_with($name, 'auth:'));

            expect($authGuardMiddleware)->toBeEmpty();
        }
    });
});
