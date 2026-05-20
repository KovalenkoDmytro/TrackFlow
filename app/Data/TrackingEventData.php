<?php

declare(strict_types=1);

namespace App\Data;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

final class TrackingEventData extends Data
{
    public function __construct(
        public readonly string $shopDomain,
        public readonly string $event,
        public readonly float $value,
        public readonly string $currency,
        public readonly string $transactionId,
        public readonly string $gclid,
        public readonly string $fbp,
        public readonly string $fbc,
        public readonly string $ttclid,
        public readonly string $gaClientId,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly DateTimeImmutable $occurredAt,
    ) {}
}
