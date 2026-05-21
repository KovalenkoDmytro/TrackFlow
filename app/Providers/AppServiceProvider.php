<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\PlatformResolverContract;
use App\Services\PlatformResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application services into the IoC container.
     *
     * PlatformResolverContract is bound to PlatformResolver so every caller
     * (Actions, Controllers) depends on the interface, not the concrete class.
     * This makes it trivial to swap the resolver in tests or feature flags.
     */
    public function register(): void
    {
        $this->app->bind(
            PlatformResolverContract::class,
            PlatformResolver::class,
        );
    }

    /**
     * Bootstrap application services after all providers have been registered.
     */
    public function boot(): void
    {
        //
    }
}
