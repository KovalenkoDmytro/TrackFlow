<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores a single shared OAuth refresh token per external provider (e.g. "google").
 *
 * Unlike PlatformIntegration (one row per shop per platform), there is exactly
 * one row per provider here — the whole app authenticates against Google's
 * GA4 Data/Admin APIs using one operator-connected account, while each shop
 * only supplies its own GA4 property_id (see ShopGa4Setting).
 *
 * The refresh_token column is encrypted on write and decrypted on read via the
 * Attribute mutator, mirroring PlatformIntegration::credentials(). Callers
 * always pass and receive the plain token and should never interact with the
 * raw encrypted value.
 */
final class OauthCredential extends Model
{
    protected $fillable = [
        'provider',
        'refresh_token',
        'scopes',
        'connected_by',
        'connected_at',
        'last_refreshed_at',
        'revoked_at',
    ];

    protected $hidden = [
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'connected_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Transparently encrypt the refresh token on write and decrypt on read.
     *
     * Callers always pass and receive the plain token string. The underlying
     * column stores the Laravel-encrypted ciphertext so it is never exposed
     * in database dumps, query logs, or JSON responses.
     */
    protected function refreshToken(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value !== null ? decrypt($value) : null,
            set: fn (?string $value) => $value !== null ? encrypt($value) : null,
        );
    }

    /** @return BelongsTo<User, $this> */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
