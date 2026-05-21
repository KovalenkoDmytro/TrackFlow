<?php

declare(strict_types=1);

namespace App\Actions\Tracking;

use App\Data\TrackingEventData;
use App\Models\TrackingEvent;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Persists an inbound tracking event to the database before platform dispatch.
 *
 * Separated from ProcessTrackingEvent so the record exists even if all platform
 * deliveries fail — providing a complete audit trail of what the storefront fired.
 *
 * Uses updateOrCreate on idempotency_key to handle duplicate webhook deliveries
 * from Shopify without creating duplicate records.
 */
final class PersistTrackingEvent
{
    use AsObject;

    /**
     * Persist the event and return the saved model.
     */
    public function handle(TrackingEventData $data, User $shop): TrackingEvent
    {
        $attributes = [
            'user_id'        => $shop->getKey(),
            'event'          => $data->event,
            'value'          => $data->value,
            'currency'       => $data->currency,
            'transaction_id' => $data->transactionId,
            'gclid'          => $data->gclid,
            'fbp'            => $data->fbp,
            'fbc'            => $data->fbc,
            'ttclid'         => $data->ttclid,
            'ga_client_id'   => $data->gaClientId,
            'ip'             => $data->ip,
            'user_agent'     => $data->userAgent,
            'occurred_at'    => $data->occurredAt,
        ];

        if ($data->idempotencyKey !== null) {
            return TrackingEvent::updateOrCreate(
                ['user_id' => $shop->getKey(), 'idempotency_key' => $data->idempotencyKey],
                $attributes,
            );
        }

        return TrackingEvent::query()->create($attributes);
    }
}
