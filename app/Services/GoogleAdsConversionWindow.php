<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ConversionActionMapping;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides whether a click conversion is still young enough for Google Ads to accept.
 *
 * Google rejects an upload with EXPIRED_EVENT when the click is older than the conversion
 * action's click_through_lookback_window_days. No click time is stored, so the event's
 * occurred_at is used as a lower bound of the click's age (the click is at least that old).
 */
final class GoogleAdsConversionWindow
{
    public const string LOCAL_CODE = 'EXPIRED_WINDOW_LOCAL';

    public const string GOOGLE_CODE = 'EXPIRED_EVENT';

    private const int CACHE_TTL_SECONDS = 6 * 3600;

    private const int FALLBACK_TTL_SECONDS = 10 * 60;

    public function __construct(private readonly GoogleAdsClient $client) {}

    /** Pure check: eligible while age <= window - margin (never below 0 for very small windows). */
    public static function isWithinWindow(float $ageDays, int $windowDays, int $marginDays): bool
    {
        return $ageDays <= max(0, $windowDays - $marginDays);
    }

    public function marginDays(): int
    {
        return max(0, (int) config('tracking.google_ads.safety_margin_days', 5));
    }

    public function defaultWindowDays(): int
    {
        return max(1, (int) config('tracking.google_ads.default_click_window_days', 30));
    }

    /**
     * Age in days of the event (lower bound of the click age); negative for future timestamps.
     */
    public function ageDays(TrackingEvent $event): float
    {
        return (now()->getTimestamp() - $event->occurred_at->getTimestamp()) / 86400;
    }

    /**
     * Decide whether the event may be uploaded.
     *
     * Same-day events (age < 1 day) are always eligible and skip the API lookup, keeping the
     * hot live path free of Google calls. The local guard applies only when the window was
     * really read from the API (or a cached API value): if the lookup failed and the config
     * default was used, the event is uploaded and Google's EXPIRED_EVENT answer decides.
     * Because of the safety margin, the last N days of a window are intentionally never
     * uploaded (N = tracking.google_ads.safety_margin_days).
     *
     * @return array{eligible: bool, age_days: float, window_days: int, from_api: bool}
     */
    public function evaluate(PlatformIntegration $integration, TrackingEvent $event, ConversionActionMapping $mapping): array
    {
        $age = $this->ageDays($event);

        if ($age < 1) {
            return ['eligible' => true, 'age_days' => $age, 'window_days' => $this->defaultWindowDays(), 'from_api' => false];
        }

        $lookup = $this->lookup($integration, (string) $mapping->external_action_id);

        return [
            'eligible' => ! $lookup['from_api'] || self::isWithinWindow($age, $lookup['days'], $this->marginDays()),
            'age_days' => $age,
            'window_days' => $lookup['days'],
            'from_api' => $lookup['from_api'],
        ];
    }

    /** Click-through lookback window of a conversion action; never throws. */
    public function windowDays(PlatformIntegration $integration, string $resourceName): int
    {
        return $this->lookup($integration, $resourceName)['days'];
    }

    /**
     * @return array{days: int, from_api: bool}
     */
    public function lookup(PlatformIntegration $integration, string $resourceName): array
    {
        $key = 'google_ads:click_window:'.$this->customerId($integration).':'.md5($resourceName);

        try {
            $cached = Cache::get($key);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_array($cached) && is_int($cached['days'] ?? null) && $cached['days'] > 0 && is_bool($cached['from_api'] ?? null)) {
            return ['days' => $cached['days'], 'from_api' => $cached['from_api']];
        }

        $window = $this->fetch($integration, $resourceName);
        $result = $window === null
            ? ['days' => $this->defaultWindowDays(), 'from_api' => false]
            : ['days' => $window, 'from_api' => true];

        try {
            Cache::put($key, $result, $window === null ? self::FALLBACK_TTL_SECONDS : self::CACHE_TTL_SECONDS);
        } catch (Throwable) {
            // A cache outage must never block an upload.
        }

        return $result;
    }

    /** response_body for a row skipped locally because the click is outside the window. */
    public function localBody(float $ageDays, int $windowDays): string
    {
        return (string) json_encode([
            'codes' => [self::LOCAL_CODE],
            'message' => sprintf(
                'Upload skipped: event is %d days old, the conversion window is %d days (safety margin %d days).',
                (int) floor($ageDays),
                $windowDays,
                $this->marginDays(),
            ),
            'skipped' => true,
            'window_days' => $windowDays,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Structured log line; never contains click ids or credentials. */
    public function log(string $message, string $source, PlatformIntegration $integration, TrackingEvent $event, float $ageDays, int $windowDays): void
    {
        Log::info($message, [
            'event_id' => $event->getKey(),
            'event_type' => $event->event,
            'click_age_days' => round($ageDays, 1),
            'window_days' => $windowDays,
            'safety_margin_days' => $this->marginDays(),
            'google_ads_customer_id' => $this->customerId($integration),
            'integration_id' => $integration->getKey(),
            'source' => $source,
        ]);
    }

    /** Mark a delivery row expired locally (no Google call) and log it. */
    public function markSkipped(PlatformDelivery $delivery, string $source, PlatformIntegration $integration, TrackingEvent $event, float $ageDays, int $windowDays): void
    {
        $delivery->update([
            'status' => PlatformDelivery::STATUS_EXPIRED,
            'response_body' => $this->localBody($ageDays, $windowDays),
        ]);

        $this->log('google_ads.conversion_skipped_outside_window', $source, $integration, $event, $ageDays, $windowDays);
    }

    private function fetch(PlatformIntegration $integration, string $resourceName): ?int
    {
        if (! preg_match('#^customers/\d+/conversionActions/\d+$#', $resourceName)) {
            return null;
        }

        try {
            $credentials = json_decode((string) $integration->credentials, true);

            if (! is_array($credentials) || ! is_array($credentials['oauth'] ?? null)) {
                return null;
            }

            $rows = $this->client->report(
                $credentials,
                $this->client->getAccessToken($credentials['oauth']),
                'SELECT conversion_action.resource_name, conversion_action.click_through_lookback_window_days '
                    .'FROM conversion_action '
                    ."WHERE conversion_action.resource_name = '{$resourceName}'",
            );

            $days = (int) ($rows[0]['conversionAction']['clickThroughLookbackWindowDays'] ?? 0);

            return $days > 0 ? $days : null;
        } catch (Throwable $e) {
            Log::warning('GoogleAdsConversionWindow: lookup failed, using default window', [
                'integration_id' => $integration->getKey(),
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return null;
        }
    }

    private function customerId(PlatformIntegration $integration): string
    {
        $credentials = json_decode((string) $integration->credentials, true);

        return str_replace('-', '', is_array($credentials) ? (string) ($credentials['customer_id'] ?? '') : '');
    }
}
