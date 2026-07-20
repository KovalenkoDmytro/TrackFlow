<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a shop (User) to its GA4 property for the read-only Data API reporting
 * feature. This is intentionally separate from PlatformIntegration/GA4
 * Measurement Protocol credentials (measurement_id + api_secret) — reporting
 * reads via the shared OauthCredential (provider "google") token, it does not
 * need per-shop OAuth credentials at all.
 */
final class ShopGa4Setting extends Model
{
    protected $fillable = [
        'user_id',
        'property_id',
        'property_display_name',
        'property_timezone',
        'property_currency',
        'active',
        'last_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
