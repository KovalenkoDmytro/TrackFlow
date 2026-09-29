<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Optional companion to ConversionPlatformContract for drivers whose API confirms receipt,
 * so "delivered" can be verified later instead of meaning only "HTTP 2xx".
 */
interface ReportsDeliveryReceipt
{
    /**
     * Small JSON string for platform_deliveries.response_body describing the most recent
     * successful uploadConversion() call; null otherwise. Must not contain tokens or PII.
     */
    public function deliveryReceipt(): ?string;
}
