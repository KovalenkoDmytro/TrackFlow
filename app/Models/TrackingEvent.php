<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TrackingEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'shop_id',
        'event',
        'value',
        'currency',
        'transaction_id',
        'gclid',
        'fbp',
        'fbc',
        'ttclid',
        'ga_client_id',
        'ip',
        'user_agent',
        'idempotency_key',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return HasMany<PlatformDelivery, $this> */
    public function platformDeliveries(): HasMany
    {
        return $this->hasMany(PlatformDelivery::class);
    }
}
