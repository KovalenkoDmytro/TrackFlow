<?php

declare(strict_types=1);

namespace App\Enums;

enum Platform: string
{
    case GoogleAds = 'google_ads';
    case Meta = 'meta';
    case TikTok = 'tiktok';
    case Ga4 = 'ga4';
}
