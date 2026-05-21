<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit record of a single delivery attempt from one TrackingEvent to one PlatformIntegration.
 *
 * One TrackingEvent produces N PlatformDelivery rows — one per active integration at the
 * time of dispatch. The status progresses from "queued" → "delivered" or "failed". The
 * platform_integration_id foreign key enables querying all delivery history for a specific
 * integration without multi-table JOINs through tracking_events.
 */
final class PlatformDelivery extends Model
{
    protected $fillable = [
        'tracking_event_id',
        'platform_integration_id',
        'platform',
        'status',
        'attempts',
        'response_code',
        'response_body',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'response_code' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TrackingEvent, $this> */
    public function trackingEvent(): BelongsTo
    {
        return $this->belongsTo(TrackingEvent::class);
    }

    /** @return BelongsTo<PlatformIntegration, $this> */
    public function platformIntegration(): BelongsTo
    {
        return $this->belongsTo(PlatformIntegration::class);
    }
}
