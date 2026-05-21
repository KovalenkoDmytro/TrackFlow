<?php

declare(strict_types=1);

namespace App\Actions\Tracking;

use App\Contracts\PlatformResolverContract;
use App\Data\TrackingEventData;
use App\Enums\Platform;
use App\Models\ConversionActionMapping;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
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

    public function __construct(private readonly PlatformResolverContract $resolver) {}

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

        foreach ($integrations as $integration) {
            $this->processIntegration($integration, $data, $trackingEvent);
        }
    }

    /**
     * Forward a single event to one platform integration and record the delivery attempt.
     *
     * Creates a PlatformDelivery row with status "queued" before the API call so a
     * record exists even if the process dies mid-flight. Google Ads requires a gclid —
     * events without one are silently skipped for that platform.
     *
     * On RuntimeException the delivery and integration error fields are updated and the
     * exception is re-thrown so the job queue retries with backoff. Partial failures
     * (returned as false) are recorded but do not trigger a retry — they represent data
     * issues that would fail identically on every attempt.
     */
    private function processIntegration(
        PlatformIntegration $integration,
        TrackingEventData $data,
        TrackingEvent $trackingEvent,
    ): void {
        if ($integration->platform === Platform::GoogleAds && empty($data->gclid)) {
            return;
        }

        $mapping = $integration->conversionActionMappings
            ->where('event', $data->event)
            ->where('active', true)
            ->first();

        if (! $mapping instanceof ConversionActionMapping) {
            Log::warning('ProcessTrackingEvent: no mapping', [
                'shop'     => $data->shopDomain,
                'platform' => $integration->platform->value,
                'event'    => $data->event,
            ]);

            return;
        }

        // Create delivery record before attempting the API call
        $delivery = PlatformDelivery::query()->create([
            'tracking_event_id'      => $trackingEvent->getKey(),
            'platform_integration_id' => $integration->getKey(),
            'platform'               => $integration->platform->value,
            'status'                 => 'queued',
        ]);

        $credentials = json_decode($integration->credentials, true);

        try {
            $platform = $this->resolver->resolve($integration->platform);
            $success  = $platform->uploadConversion($credentials, $data, $mapping);

            $delivery->update([
                'status'   => $success ? 'delivered' : 'partial_failure',
                'attempts' => $delivery->attempts + 1,
                'sent_at'  => now(),
            ]);

            $integration->last_success_at = $success ? now() : $integration->last_success_at;

            if (! $success) {
                $integration->last_error    = 'partial_failure';
                $integration->last_error_at = now();
            }

            $integration->save();

            Log::info('ProcessTrackingEvent: dispatched', [
                'platform' => $integration->platform->value,
                'event'    => $data->event,
                'success'  => $success,
            ]);
        } catch (\RuntimeException $e) {
            $delivery->update([
                'status'        => 'failed',
                'attempts'      => $delivery->attempts + 1,
                'response_body' => $e->getMessage(),
            ]);

            $integration->last_error    = $e->getMessage();
            $integration->last_error_at = now();
            $integration->save();

            Log::error('ProcessTrackingEvent: failed', [
                'platform' => $integration->platform->value,
                'event'    => $data->event,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
