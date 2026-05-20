<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Shop extends Model
{
    protected $fillable = [
        'shopify_domain',
        'access_token',
        'pixel_secret',
        'shopify_pixel_id',
        'plan',
        'installed_at',
        'uninstalled_at',
    ];

    protected $hidden = [
        'access_token',
        'pixel_secret',
    ];

    protected function casts(): array
    {
        return [
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }

    protected function accessToken(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => decrypt($value),
            set: fn (string $value) => encrypt($value),
        );
    }

    protected function pixelSecret(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value !== null ? decrypt($value) : null,
            set: fn (?string $value) => $value !== null ? encrypt($value) : null,
        );
    }

    /** @return HasMany<PlatformIntegration, $this> */
    public function platformIntegrations(): HasMany
    {
        return $this->hasMany(PlatformIntegration::class);
    }

    /** @return HasMany<TrackingEvent, $this> */
    public function trackingEvents(): HasMany
    {
        return $this->hasMany(TrackingEvent::class);
    }
}
