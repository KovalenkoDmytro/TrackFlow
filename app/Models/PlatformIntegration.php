<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PlatformIntegration extends Model
{
    protected $fillable = [
        'shop_id',
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
            'platform' => Platform::class,
            'active' => 'boolean',
            'settings' => 'array',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    protected function credentials(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value !== null ? decrypt($value) : null,
            set: fn (?string $value) => $value !== null ? encrypt($value) : null,
        );
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return HasMany<ConversionActionMapping, $this> */
    public function conversionActionMappings(): HasMany
    {
        return $this->hasMany(ConversionActionMapping::class);
    }
}
