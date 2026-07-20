<?php

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->validateCsrfTokens(except: [
            'webhook/*',
        ]);

        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
        ]);

        // This app never registers a route named "login" — it is a
        // Shopify-embedded app with no classic login page, and the only
        // `auth:web` routes are the /operator/google/* operator routes (see
        // routes/web.php) plus routes/api.php's `auth:web` group (which
        // already gets a JSON 401 regardless, via shouldReturnJson()).
        // Laravel's default middleware config redirects unauthenticated
        // guests to `route('login')`, which throws a RouteNotFoundException
        // (surfacing as an unhandled 500) because that route does not exist.
        // Passing `null` here overrides that default so guests get a plain
        // 401 response instead of a broken redirect — see
        // tests/Feature/Google/GoogleOAuthRoutesTest.php.
        $middleware->redirectGuestsTo(null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
