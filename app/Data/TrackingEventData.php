<?php

declare(strict_types=1);

namespace App\Data;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

/**
 * Immutable DTO carrying all attribution signals for a single Shopify storefront event.
 *
 * Populated by ConversionController from the incoming Web Pixel webhook payload and
 * passed to ProcessTrackingEvent as the job argument. Every platform driver
 * receives this object and picks the fields it needs (gclid for Google Ads,
 * fbp/fbc for Meta, ttclid for TikTok, gaClientId for GA4).
 *
 * spatie/laravel-data handles serialisation/deserialisation when the DTO is
 * stored on the queue, so all properties must be serialisable primitives or
 * types that Data supports natively.
 */
final class TrackingEventData extends Data
{
    /**
     * @param string             $shopDomain     Shopify shop domain (e.g. "example.myshopify.com"). Used to resolve the User record.
     * @param string             $event          Shopify e-commerce event name (e.g. "purchase", "add_to_cart").
     * @param float              $value          Monetary value of the conversion. 0.0 for non-purchase events.
     * @param string             $currency       ISO 4217 currency code (e.g. "USD").
     * @param string|null        $transactionId  Shopify order ID, used as the deduplication key for purchase events.
     * @param string|null        $gclid          Google Click ID from the URL parameter. Required for Google Ads upload.
     * @param string|null        $fbp            Meta browser pixel cookie value (_fbp). Used for Meta CAPI deduplication.
     * @param string|null        $fbc            Meta click cookie value (_fbc). Used for Meta CAPI attribution.
     * @param string|null        $ttclid         TikTok Click ID. Required for TikTok event upload.
     * @param string|null        $gaClientId     Google Analytics client ID (_ga cookie). Used for GA4 Measurement Protocol.
     * @param string|null        $ip             Client IP address for server-side geolocation enrichment.
     * @param string|null        $userAgent      Client User-Agent string for device/browser enrichment.
     * @param string|null        $idempotencyKey Unique key for the conversion event, used to prevent double-counting on retries.
     * @param DateTimeImmutable  $occurredAt     Timestamp of the event on the storefront, in the shop's timezone.
     */
    public function __construct(
        public readonly string $shopDomain,
        public readonly string $event,
        public readonly float $value,
        public readonly string $currency,
        public readonly ?string $transactionId,
        public readonly ?string $gclid,
        public readonly ?string $fbp,
        public readonly ?string $fbc,
        public readonly ?string $ttclid,
        public readonly ?string $gaClientId,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly ?string $idempotencyKey,
        public readonly DateTimeImmutable $occurredAt,
    ) {}
}
