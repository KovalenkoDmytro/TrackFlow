<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TrackingEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Raw record of a single inbound storefront event — one row per POST to /pixel/track.
 *
 * Persisted by PersistTrackingEvent before any platform dispatch begins, so the record
 * exists in the database even if every downstream delivery fails. Each TrackingEvent may
 * produce multiple PlatformDelivery rows: one per active PlatformIntegration at dispatch
 * time. idempotency_key enforces exactly-once semantics for duplicate webhook deliveries
 * from Shopify (unique on user_id + idempotency_key).
 */
final class TrackingEvent extends Model
{
    /** @use HasFactory<TrackingEventFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PlatformDelivery, $this> */
    public function platformDeliveries(): HasMany
    {
        return $this->hasMany(PlatformDelivery::class);
    }
}
