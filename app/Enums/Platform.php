<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Enumeration of the conversion-tracking platforms TrackFlow supports.
 *
 * Each case corresponds to a ConversionPlatformContract implementation registered
 * in PlatformResolver. The string values are stored in the platform_integrations
 * table and must never change once records exist in production.
 */
enum Platform: string
{
    /** Google Ads click-conversion upload via the Google Ads REST API v24. */
    case GoogleAds = 'google_ads';

    /** Meta Conversions API (CAPI) for Facebook and Instagram ads. */
    case Meta = 'meta';

    /** TikTok Events API for TikTok for Business ad attribution. */
    case TikTok = 'tiktok';

    /** Google Analytics 4 Measurement Protocol for GA4 event ingestion. */
    case GoogleAnalytics4 = 'ga4';
}
