<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\TrackingEventData;
use App\Models\ConversionActionMapping;
use App\Models\PlatformIntegration;

/**
 * Defines the contract every conversion-tracking platform must fulfil.
 *
 * Each platform (Google Ads, Meta, TikTok, GA4) implements this interface.
 * New platforms are added by creating a new class — existing code never changes
 * (Open/Closed Principle).
 */
interface ConversionPlatformContract
{
    /**
     * Verify that the stored credentials can reach the platform API.
     *
     * Called before saving credentials so the merchant gets immediate feedback.
     *
     * @param array<string, mixed> $credentials Decrypted credentials from PlatformIntegration.
     *
     * @throws \RuntimeException When the API rejects the credentials.
     */
    public function testCredentials(array $credentials): void;

    /**
     * Provision all required conversion actions / pixels / events on the platform.
     *
     * Called once after the merchant connects a platform. Creates the remote
     * counterparts and persists them as ConversionActionMapping records.
     *
     * @throws \RuntimeException When provisioning fails fatally (individual failures are logged and skipped).
     */
    public function setupConversionActions(PlatformIntegration $integration): void;

    /**
     * Send a single conversion event to the platform.
     *
     * Returns true on success, false on partial failure (the job should NOT retry
     * for partial failures — they are typically unrecoverable data issues).
     * Throws RuntimeException for network/auth errors so the job retries.
     *
     * @param array<string, mixed> $credentials Decrypted credentials.
     *
     * @throws \RuntimeException On network or authentication errors.
     */
    public function uploadConversion(
        array $credentials,
        TrackingEventData $data,
        ConversionActionMapping $mapping,
    ): bool;
}
