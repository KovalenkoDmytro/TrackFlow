<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\PartialFailure;

/**
 * Optional companion to ConversionPlatformContract for drivers that can explain why
 * uploadConversion() returned false.
 */
interface ReportsPartialFailure
{
    /** Reason for the most recent uploadConversion() call that returned false; null otherwise. */
    public function partialFailure(): ?PartialFailure;
}
