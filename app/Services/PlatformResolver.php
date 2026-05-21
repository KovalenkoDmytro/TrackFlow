<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ConversionPlatformContract;
use App\Contracts\PlatformResolverContract;
use App\Enums\Platform;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a Platform enum to its ConversionPlatformContract implementation.
 *
 * Uses the Laravel IoC container so each platform class benefits from
 * automatic dependency injection. Adding a new platform means adding one
 * case here — all callers remain untouched (Open/Closed Principle).
 */
final class PlatformResolver implements PlatformResolverContract
{
    public function __construct(private readonly Container $container) {}

    /**
     * Return the ConversionPlatformContract implementation bound to the given platform.
     *
     * Each case resolves through the container so platform services receive their
     * own constructor dependencies automatically. Platforms without a registered
     * implementation throw immediately rather than silently swallowing events.
     *
     * @throws \InvalidArgumentException When the platform has no registered driver.
     */
    public function resolve(Platform $platform): ConversionPlatformContract
    {
        return match ($platform) {
            Platform::GoogleAds => $this->container->make(GoogleAdsClient::class),
            default => throw new \InvalidArgumentException(
                "Platform [{$platform->value}] has no registered ConversionPlatformContract implementation."
            ),
        };
    }
}
