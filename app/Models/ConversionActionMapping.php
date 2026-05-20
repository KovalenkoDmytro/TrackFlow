<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ConversionActionMapping extends Model
{
    protected $fillable = [
        'platform_integration_id',
        'event',
        'external_action_id',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<PlatformIntegration, $this> */
    public function platformIntegration(): BelongsTo
    {
        return $this->belongsTo(PlatformIntegration::class);
    }
}
