<?php

declare(strict_types=1);
use Illuminate\Support\Arr;

/**
 * Evaluates config/shopify-app.php afresh with the given raw environment, the same
 * way config:cache does (the file is executed once with the real env).
 *
 * @return array<string, mixed>
 */
function shopifyConfigWithEnv(string $appEnv, string $bypass): array
{
    $vars = ['APP_ENV' => $appEnv, 'SHOPIFY_DEV_AUTH_BYPASS' => $bypass];
    $previous = [];

    foreach ($vars as $key => $value) {
        $previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    try {
        /** @var array<string, mixed> $config */
        $config = require config_path('shopify-app.php');
    } finally {
        foreach ($previous as $key => [$env, $server, $getenv]) {
            $env === null ? Arr::forget($_ENV, $key) : $_ENV[$key] = $env;
            $server === null ? Arr::forget($_SERVER, $key) : $_SERVER[$key] = $server;
            putenv($getenv === false ? $key : "{$key}={$getenv}");
        }
    }

    return $config;
}

describe('shopify-app.dev_auth_bypass config gating', function (): void {
    it('is inert in non-local environments even when the env flag is true', function (string $appEnv): void {
        $config = shopifyConfigWithEnv($appEnv, 'true');

        expect($config['dev_auth_bypass'])->toBeFalse()
            ->and($config['dev_auth_bypass_requested'])->toBeTrue(); // raw value stays visible to the boot guard
    })->with(['production', 'staging', 'testing']);

    it('is enabled only when APP_ENV is local and the flag is true', function (): void {
        expect(shopifyConfigWithEnv('local', 'true')['dev_auth_bypass'])->toBeTrue()
            ->and(shopifyConfigWithEnv('local', 'false')['dev_auth_bypass'])->toBeFalse();
    });
});

describe('spa view', function (): void {
    it('includes App Bridge when the effective bypass flag is off', function (): void {
        config(['shopify-app.dev_auth_bypass' => false, 'shopify-app.api_key' => 'key123']);

        $html = view('spa')->render();

        expect($html)->toContain('app-bridge.js')->and($html)->toContain('key123');
    });
});
