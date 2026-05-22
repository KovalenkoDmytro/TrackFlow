<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Osiset\ShopifyApp\Contracts\ShopModel as IShopModel;
use Osiset\ShopifyApp\Traits\ShopModel;

class User extends Authenticatable implements IShopModel
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, ShopModel;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'tracking_secret',
        'shopify_pixel_id',
        'plan',
        'installed_at',
        'uninstalled_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'tracking_secret',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }

    protected function trackingSecret(): Attribute
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

    /** @return HasManyThrough<PlatformDelivery, PlatformIntegration, $this> */
    public function platformDeliveries(): HasManyThrough
    {
        return $this->hasManyThrough(PlatformDelivery::class, PlatformIntegration::class);
    }
}
