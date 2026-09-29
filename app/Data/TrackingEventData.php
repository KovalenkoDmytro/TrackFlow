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
     * Google Click ID, normalized at this single shared construction point.
     *
     * Whitespace-only/padded values passed to the constructor are trimmed and
     * collapsed to null here so every consumer of this DTO
     * (PersistTrackingEvent's hashing, ProcessTrackingEvent's
     * `empty($data->gclid)` Google Ads gate, GoogleAdsClient's upload
     * payload) sees the same already-normalized value instead of each
     * needing to remember to trim separately. Matches
     * GetGoogleAdsAttribution's `TRIM(gclid) <> ''` match condition.
     */
    public readonly ?string $gclid;

    /**
     * Page URL the event fired on, normalized to https origin + path (see normalizeSourceUrl()).
     *
     * Travels in the queued job payload (this DTO is serialised onto the queue and
     * ProcessTrackingEvent never reloads it from the database), so it needs no column.
     */
    public readonly ?string $eventSourceUrl;

    /**
     * @param  string  $shopDomain  Shopify shop domain (e.g. "example.myshopify.com"). Used to resolve the User record.
     * @param  string  $event  Shopify e-commerce event name (e.g. "purchase", "add_to_cart").
     * @param  float  $value  Monetary value of the conversion. 0.0 for non-purchase events.
     * @param  string  $currency  ISO 4217 currency code (e.g. "USD").
     * @param  string|null  $transactionId  Shopify order ID, used as the deduplication key for purchase events.
     * @param  string|null  $gclid  Google Click ID from the URL parameter. Required for Google Ads upload. Normalized (trimmed, blank collapsed to null) before storage.
     * @param  string|null  $fbp  Meta browser pixel cookie value (_fbp). Used for Meta CAPI deduplication.
     * @param  string|null  $fbc  Meta click cookie value (_fbc). Used for Meta CAPI attribution.
     * @param  string|null  $ttclid  TikTok Click ID. Required for TikTok event upload.
     * @param  string|null  $gaClientId  Google Analytics client ID (_ga cookie). Used for GA4 Measurement Protocol.
     * @param  string|null  $ip  Client IP address for server-side geolocation enrichment.
     * @param  string|null  $userAgent  Client User-Agent string for device/browser enrichment.
     * @param  string|null  $idempotencyKey  Unique key for the conversion event, used to prevent double-counting on retries.
     * @param  DateTimeImmutable  $occurredAt  Timestamp of the event on the storefront, in the shop's timezone.
     * @param  string|null  $eventSourceUrl  Storefront page URL for Meta's event_source_url. Reduced to https origin + path; anything else becomes null.
     */
    public function __construct(
        public readonly string $shopDomain,
        public readonly string $event,
        public readonly float $value,
        public readonly string $currency,
        public readonly ?string $transactionId,
        ?string $gclid,
        public readonly ?string $fbp,
        public readonly ?string $fbc,
        public readonly ?string $ttclid,
        public readonly ?string $gaClientId,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly ?string $idempotencyKey,
        public readonly DateTimeImmutable $occurredAt,
        ?string $eventSourceUrl = null,
    ) {
        $trimmedGclid = $gclid !== null ? trim($gclid) : null;
        $this->gclid = $trimmedGclid === null || $trimmedGclid === '' ? null : $trimmedGclid;
        $this->eventSourceUrl = self::normalizeSourceUrl($eventSourceUrl);
    }

    /**
     * Copy of this event carrying the given fbc (used for the Meta send only).
     */
    public function withFbc(?string $fbc): self
    {
        return new self(
            shopDomain: $this->shopDomain,
            event: $this->event,
            value: $this->value,
            currency: $this->currency,
            transactionId: $this->transactionId,
            gclid: $this->gclid,
            fbp: $this->fbp,
            fbc: $fbc,
            ttclid: $this->ttclid,
            gaClientId: $this->gaClientId,
            ip: $this->ip,
            userAgent: $this->userAgent,
            idempotencyKey: $this->idempotencyKey,
            occurredAt: $this->occurredAt,
            eventSourceUrl: $this->eventSourceUrl,
        );
    }

    /**
     * Reduce a URL to `https://host[:port]/path`; null when it is not a plain https URL.
     *
     * Query string, fragment and credentials are always dropped (they can carry tokens or
     * click ids), and paths under checkout/order/account style prefixes are truncated
     * because the next segment is a private token (/checkouts/cn/<token>/thank-you).
     */
    public static function normalizeSourceUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url(trim($url));

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        if (preg_match('#^((?:/[a-z]{2}(?:-[a-z]{2})?)?/(?:checkouts|orders|gift_cards|account))(?:/|$)#i', $path, $m) === 1) {
            $path = $m[1];
        }

        return 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$path;
    }
}
