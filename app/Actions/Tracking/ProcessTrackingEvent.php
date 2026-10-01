<?php

declare(strict_types=1);

namespace App\Actions\Tracking;

use App\Contracts\PlatformResolverContract;
use App\Contracts\ReportsDeliveryReceipt;
use App\Contracts\ReportsPartialFailure;
use App\Data\PartialFailure;
use App\Data\TrackingEventData;
use App\Enums\Platform;
use App\Models\ConversionActionMapping;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Services\GoogleAdsOtherAccountClassifier;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Background job that forwards a Shopify event to every active platform integration.
 *
 * Iterates all active PlatformIntegration records for the shop, resolves the
 * appropriate ConversionPlatformContract driver for each, and calls
 * uploadConversion(). This design means adding Meta or TikTok requires only a
 * new service class and a PlatformResolver case — this Action is never touched.
 *
 * lorisleiva/laravel-actions resolves this class via the container, so
 * constructor injection of PlatformResolverContract works automatically when
 * the job is dispatched.
 */
final class ProcessTrackingEvent
{
    use AsJob, AsObject;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 60, 120];

    public int $timeout = 60;

    public function __construct(
        private readonly PlatformResolverContract $resolver,
        private readonly GoogleAdsOtherAccountClassifier $classifier,
    ) {}

    /**
     * Dispatch the event to all active platform integrations for the shop.
     *
     * Persists the raw event to tracking_events first so the record exists even
     * if every platform delivery fails. Returns early without error when the shop
     * domain is unknown — this is expected during the brief window between a pixel
     * firing and the shop record being seeded after install.
     */
    public function handle(TrackingEventData $data): void
    {
        $shop = User::query()->where('name', $data->shopDomain)->first();

        if (! $shop instanceof User) {
            return;
        }

        // Persist the raw event first — exists in DB even if all deliveries fail
        $trackingEvent = PersistTrackingEvent::make()->handle($data, $shop);

        $integrations = $shop->platformIntegrations()
            ->with('conversionActionMappings')
            ->where('active', true)
            ->get();

        // Isolate failures per integration so one platform's error cannot stop later
        // integrations from receiving the event. The first failure is re-thrown after
        // the loop so the queue still retries; on retry, integrations that already
        // reached a terminal delivery status are skipped (see processIntegration()).
        $firstFailure = null;

        foreach ($integrations as $integration) {
            try {
                $this->processIntegration($integration, $data, $trackingEvent);
            } catch (\Throwable $e) {
                $firstFailure ??= $e;
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }

    /**
     * Forward a single event to one platform integration and record the delivery attempt.
     *
     * Creates a PlatformDelivery row with status "queued" before the API call so a
     * record exists even if the process dies mid-flight. Google Ads requires a gclid and
     * GA4 requires a client id — events without one are skipped for that platform.
     *
     * On failure the delivery and integration error fields are updated and the exception
     * is re-thrown to handle(), which defers it until every integration has been tried so
     * the job queue retries with backoff. Partial failures (returned as false) are recorded
     * but do not trigger a retry — they represent data issues that would fail identically
     * on every attempt.
     *
     * Retry-safe: an existing delivery row for this event + integration in a terminal
     * status (delivered / partial_failure / other_account) is skipped, and a "queued"/"failed" row left by
     * an earlier attempt is reused instead of inserting a duplicate.
     */
    private function processIntegration(
        PlatformIntegration $integration,
        TrackingEventData $data,
        TrackingEvent $trackingEvent,
    ): void {
        if ($integration->platform === Platform::GoogleAds && empty($data->gclid)) {
            return;
        }

        // No _ga cookie (no consent / blocked): GA4 cannot attribute the event. This is
        // a normal data condition, not a delivery failure, so no delivery row is created.
        if ($integration->platform === Platform::GoogleAnalytics4 && empty($data->gaClientId)) {
            Log::debug('ProcessTrackingEvent: skipping GA4, no client id', [
                'shop' => $data->shopDomain,
                'event' => $data->event,
            ]);

            return;
        }

        $mapping = $integration->conversionActionMappings
            ->where('event', $data->event)
            ->where('active', true)
            ->first();

        if (! $mapping instanceof ConversionActionMapping) {
            Log::warning('ProcessTrackingEvent: no mapping', [
                'shop' => $data->shopDomain,
                'platform' => $integration->platform->value,
                'event' => $data->event,
            ]);

            return;
        }

        $delivery = PlatformDelivery::query()
            ->where('tracking_event_id', $trackingEvent->getKey())
            ->where('platform_integration_id', $integration->getKey())
            ->latest('id')
            ->first();

        if ($delivery instanceof PlatformDelivery && in_array($delivery->status, ['delivered', 'partial_failure', GoogleAdsOtherAccountClassifier::STATUS], true)) {
            return;
        }

        // Create delivery record before attempting the API call (reused on retry)
        if ($delivery instanceof PlatformDelivery) {
            $delivery->update(['status' => 'queued']);
        } else {
            $delivery = PlatformDelivery::query()->create([
                'tracking_event_id' => $trackingEvent->getKey(),
                'platform_integration_id' => $integration->getKey(),
                'platform' => $integration->platform->value,
                'status' => 'queued',
            ]);
        }

        $credentials = json_decode($integration->credentials, true);

        // Ownership of this click was already established for the connected account: Google
        // would reject it again, so record the outcome without calling the API.
        if ($integration->platform === Platform::GoogleAds && $this->classifier->isOwnershipKnown($integration, $trackingEvent)) {
            $this->recordSkipped($integration, $delivery, $trackingEvent);

            return;
        }

        try {
            $platform = $this->resolver->resolve($integration->platform);
            // Recover a click id lost after the landing page, for the Meta payload only.
            $payload = $integration->platform === Platform::Meta
                ? ResolveMetaFbc::make()->handle($data, (int) $integration->user_id)
                : $data;
            $success = $platform->uploadConversion($credentials, $payload, $mapping);

            $failure = ! $success && $platform instanceof ReportsPartialFailure ? $platform->partialFailure() : null;
            $receipt = $success && $platform instanceof ReportsDeliveryReceipt ? $platform->deliveryReceipt() : null;

            $otherAccount = $failure instanceof PartialFailure
                && $integration->platform === Platform::GoogleAds
                && $this->classifier->isOtherAccountFailure($integration, $trackingEvent, $failure);

            $delivery->update([
                'status' => $success ? 'delivered' : ($otherAccount ? GoogleAdsOtherAccountClassifier::STATUS : 'partial_failure'),
                'attempts' => $delivery->attempts + 1,
                'sent_at' => now(),
                ...($failure instanceof PartialFailure ? [
                    'response_code' => $failure->httpStatus,
                    'response_body' => $otherAccount
                        ? $this->classifier->body($failure, $this->classifier->customerId($integration))
                        : $failure->toJson(),
                ] : []),
                ...($receipt !== null ? ['response_code' => 200, 'response_body' => $receipt] : []),
            ]);

            $integration->last_success_at = $success ? now() : $integration->last_success_at;

            if ($otherAccount) {
                // Not a delivery failure: the click belongs to an unconnected account, so the
                // integration's last_error is left alone.
                Log::info('ProcessTrackingEvent: click belongs to another Google Ads account', [
                    'integration_id' => $integration->getKey(),
                    'tracking_event_id' => $trackingEvent->getKey(),
                    'shop' => $data->shopDomain,
                    'customer_id' => $this->classifier->customerId($integration),
                ]);
            } elseif (! $success) {
                $integration->last_error = $failure instanceof PartialFailure ? $failure->lastError() : 'partial_failure';
                $integration->last_error_at = now();

                Log::warning('ProcessTrackingEvent: partial failure', [
                    'integration_id' => $integration->getKey(),
                    'tracking_event_id' => $trackingEvent->getKey(),
                    'shop' => $data->shopDomain,
                    'platform' => $integration->platform->value,
                    'codes' => $failure?->codes,
                    'message' => $failure?->message,
                ]);
            }

            $integration->save();

            Log::info('ProcessTrackingEvent: dispatched', [
                'integration_id' => $integration->getKey(),
                'tracking_event_id' => $trackingEvent->getKey(),
                'shop' => $data->shopDomain,
                'platform' => $integration->platform->value,
                'event' => $data->event,
                'success' => $success,
            ]);
        } catch (\Throwable $e) {
            $delivery->update([
                'status' => 'failed',
                'attempts' => $delivery->attempts + 1,
                'response_body' => $e->getMessage(),
            ]);

            $integration->last_error = $e->getMessage();
            $integration->last_error_at = now();
            $integration->save();

            Log::error('ProcessTrackingEvent: failed', [
                'platform' => $integration->platform->value,
                'event' => $data->event,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function recordSkipped(PlatformIntegration $integration, PlatformDelivery $delivery, TrackingEvent $trackingEvent): void
    {
        $customerId = $this->classifier->customerId($integration);

        $delivery->update([
            'status' => GoogleAdsOtherAccountClassifier::STATUS,
            'response_body' => $this->classifier->skippedBody($customerId),
        ]);

        Log::info('ProcessTrackingEvent: upload skipped, click owner already known to be another Google Ads account', [
            'integration_id' => $integration->getKey(),
            'tracking_event_id' => $trackingEvent->getKey(),
            'customer_id' => $customerId,
        ]);
    }
}
