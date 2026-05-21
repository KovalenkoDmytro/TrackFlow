<?php

declare(strict_types=1);

namespace App\Actions\GoogleAds;

use App\Contracts\PlatformResolverContract;
use App\Models\PlatformIntegration;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Background job dispatched after a merchant successfully connects a platform.
 *
 * Delegates entirely to the platform's ConversionPlatformContract implementation
 * so this Action never needs to change when new platforms are added (Open/Closed
 * Principle). The concrete provisioning logic lives in the platform service class.
 *
 * lorisleiva/laravel-actions resolves this class via the container, so
 * constructor injection of PlatformResolverContract works automatically when
 * the job is dispatched.
 */
final class CreateConversionActions
{
    use AsJob, AsObject;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(private readonly PlatformResolverContract $resolver) {}

    /**
     * Provision all conversion actions for the given platform integration.
     *
     * Resolves the correct platform driver and calls setupConversionActions(),
     * which creates the remote conversion actions and persists ConversionActionMapping
     * records so ProcessTrackingEvent can look up resource names at dispatch time.
     */
    public function handle(PlatformIntegration $integration): void
    {
        $platform = $this->resolver->resolve($integration->platform);
        $platform->setupConversionActions($integration);
    }
}
