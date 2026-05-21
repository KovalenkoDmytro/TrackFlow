<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a Shopify event name to the platform's externally created action identifier.
 *
 * One record exists per (platform_integration, event) pair. The external_action_id
 * column holds the platform-specific handle needed to upload a conversion:
 * for Google Ads this is the resource name (e.g. "customers/123/conversionActions/456"),
 * for Meta it would be a pixel event name, for TikTok an event type string.
 *
 * Records are created by CreateConversionActions after the merchant connects a
 * platform, and looked up by ProcessTrackingEvent at dispatch time.
 */
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
