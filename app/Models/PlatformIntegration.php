<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stores per-shop, per-platform credentials and operational metadata.
 *
 * One record exists for each platform a merchant has connected (e.g. one for
 * Google Ads, one for Meta). The credentials column is encrypted on write and
 * decrypted on read via the Attribute mutator — callers always receive plain
 * JSON and should never interact with the raw encrypted value.
 *
 * The last_success_at, last_error, and last_error_at columns are updated by
 * ProcessTrackingEvent after each upload attempt and surfaced in the settings
 * UI to give merchants visibility into integration health.
 */
final class PlatformIntegration extends Model
{
    protected $fillable = [
        'user_id',
        'platform',
        'active',
        'credentials',
        'settings',
        'last_success_at',
        'last_error',
        'last_error_at',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'platform'       => Platform::class,
            'active'         => 'boolean',
            'settings'       => 'array',
            'last_success_at' => 'datetime',
            'last_error_at'  => 'datetime',
        ];
    }

    /**
     * Transparently encrypt credentials on write and decrypt on read.
     *
     * Callers always pass and receive plain JSON strings. The underlying column
     * stores the Laravel-encrypted ciphertext so credentials are never exposed
     * in database dumps or query logs.
     */
    protected function credentials(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value !== null ? decrypt($value) : null,
            set: fn (?string $value) => $value !== null ? encrypt($value) : null,
        );
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ConversionActionMapping, $this> */
    public function conversionActionMappings(): HasMany
    {
        return $this->hasMany(ConversionActionMapping::class);
    }
}
