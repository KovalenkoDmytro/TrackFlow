<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\Platform;

/**
 * Resolves a Platform enum value to its ConversionPlatformContract implementation.
 *
 * Decouples callers from concrete service classes — callers depend only on this
 * interface (Dependency Inversion Principle).
 */
interface PlatformResolverContract
{
    /**
     * Return the ConversionPlatformContract implementation for the given platform.
     *
     * @throws \InvalidArgumentException When the platform has no registered implementation.
     */
    public function resolve(Platform $platform): ConversionPlatformContract;
}
