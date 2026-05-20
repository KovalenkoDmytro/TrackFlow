<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlatformDelivery extends Model
{
    protected $fillable = [
        'tracking_event_id',
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
}
