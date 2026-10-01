<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\PartialFailure;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for the `other_account` delivery outcome.
 *
 * Google rejects an upload with INVALID_CUSTOMER_FOR_CLICK when the click belongs to a Google
 * Ads account other than the connected one (e.g. a previous agency's account). That is not a
 * delivery failure of this app. The verdict is only trusted when the click report of the CURRENT
 * customer id was synced for enough days and does not contain the click, otherwise the rejection
 * stays a regular partial failure.
 */
final class GoogleAdsOtherAccountClassifier
{
    public const string STATUS = 'other_account';

    public const string ERROR_CODE = 'INVALID_CUSTOMER_FOR_CLICK';

    public function customerId(PlatformIntegration $integration): string
    {
        $credentials = json_decode((string) $integration->credentials, true);

        return str_replace('-', '', is_array($credentials) ? (string) ($credentials['customer_id'] ?? '') : '');
    }

    /** Whether enough click-report days of the current customer id exist to trust a "not found" verdict. */
    public function hasSyncCoverage(PlatformIntegration $integration, string $customerId): bool
    {
        if ($customerId === '') {
            return false;
        }

        // Rows kept from a previously connected customer id say nothing about this account.
        $checkedDays = DB::table('google_ads_click_syncs')
            ->where('platform_integration_id', $integration->getKey())
            ->where('customer_id', $customerId)->count();

        return $checkedDays >= (int) config('alerts.click_ownership_min_synced_days');
    }

    public function gclidHash(TrackingEvent $event): ?string
    {
        if (is_string($event->gclid_hash) && $event->gclid_hash !== '') {
            return $event->gclid_hash;
        }

        $gclid = trim((string) $event->gclid);

        return $gclid === '' ? null : hash('sha256', $gclid);
    }

    public function isCustomerForClickFailure(?PartialFailure $failure): bool
    {
        return $failure?->primaryCode() === self::ERROR_CODE;
    }

    /**
     * True when the rejection proves the click belongs to another, unconnected account:
     * INVALID_CUSTOMER_FOR_CLICK, sufficient sync coverage and no match in our synced clicks.
     */
    public function isOtherAccountFailure(PlatformIntegration $integration, TrackingEvent $event, ?PartialFailure $failure): bool
    {
        if (! $this->isCustomerForClickFailure($failure)) {
            return false;
        }

        return $this->isUnmatchedClick($integration, $event);
    }

    /** Coverage is sufficient AND the event's click is absent from the current account's synced clicks. */
    public function isUnmatchedClick(PlatformIntegration $integration, TrackingEvent $event): bool
    {
        $customerId = $this->customerId($integration);
        $hash = $this->gclidHash($event);

        if ($hash === null || ! $this->hasSyncCoverage($integration, $customerId)) {
            return false;
        }

        return ! DB::table('google_ads_clicks')
            ->where('platform_integration_id', $integration->getKey())
            ->where('customer_id', $customerId)
            ->where('gclid_hash', $hash)
            ->exists();
    }

    /**
     * True when an earlier event with the same click id was already classified as belonging to
     * another account while the integration pointed at the same customer id: no upload needed.
     */
    public function isOwnershipKnown(PlatformIntegration $integration, TrackingEvent $event): bool
    {
        $hash = $this->gclidHash($event);
        $customerId = $this->customerId($integration);

        if ($hash === null || $customerId === '') {
            return false;
        }

        return PlatformDelivery::query()
            ->where('platform_integration_id', $integration->getKey())
            ->where('status', self::STATUS)
            ->where('response_body', 'like', '%"customer_id":"'.$customerId.'"%')
            ->whereHas('trackingEvent', fn ($query) => $query
                ->where(fn ($match) => $match
                    ->where('gclid_hash', $hash)
                    // Events not hashed yet (hashing happens during click sync) match on the raw click id.
                    ->orWhere(fn ($legacy) => $legacy->whereNull('gclid_hash')->where('gclid', $event->gclid)))
                ->whereKeyNot($event->getKey()))
            ->exists();
    }

    /** response_body for a row classified from a real partial failure. */
    public function body(PartialFailure $failure, string $customerId): string
    {
        $decoded = json_decode($failure->toJson(), true);

        return $this->encode(is_array($decoded) ? $decoded : ['codes' => $failure->codes], $customerId);
    }

    /** response_body for a row whose upload was skipped because ownership was already known. */
    public function skippedBody(string $customerId): string
    {
        return $this->encode([
            'codes' => [self::ERROR_CODE],
            'message' => 'Upload skipped: this click was already found to belong to another Google Ads account.',
            'skipped' => true,
        ], $customerId);
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload, string $customerId): string
    {
        return (string) json_encode($payload + ['customer_id' => $customerId], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
