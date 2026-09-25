<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\TrackingEventData;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;

/**
 * Validates an inbound Web Pixel tracking event payload.
 *
 * Authentication (shop lookup + tracking secret verification) is intentionally
 * NOT done here — that is the responsibility of ConversionAuthenticator.
 * This request only enforces structural rules on the incoming JSON.
 */
final class TrackEventRequest extends FormRequest
{
    /**
     * Allowed clock-skew tolerance for a client-supplied occurred_at timestamp.
     */
    private const int MAX_FUTURE_SKEW_MINUTES = 5;

    /**
     * Matches GoogleAdsClickSync's 90-day click-report retention window (see
     * GoogleAdsClickSync::sync()), plus a small buffer. An event occurring
     * outside this window could never be verified against synced click data,
     * so it is rejected up front rather than silently landing in reports
     * with no way to be matched or, if backdated far enough, mis-filed into
     * an arbitrary historical window.
     *
     * This request is shared by every platform (Meta, TikTok, GA4, Google
     * Ads — see ProcessTrackingEvent::handle dispatching to all active
     * integrations), but this bound is only meaningful for Google Ads
     * attribution. It is therefore applied conditionally, only when the
     * payload carries a gclid, so a late-arriving non-Google-Ads event isn't
     * dropped for a retention window that doesn't concern it. The future
     * clock-skew bound above stays universal since it is a general sanity
     * check, not a Google-Ads-specific one.
     */
    private const int MAX_PAST_DAYS = 95;

    public function authorize(): bool
    {
        return true; // authentication is handled by ConversionAuthenticator in the controller
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'shop_domain' => ['required', 'string'],
            'tracking_secret' => ['required', 'string'],
            'event' => ['required', 'string'],
            'value' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'transaction_id' => ['sometimes', 'nullable', 'string'],
            'gclid' => ['sometimes', 'nullable', 'string'],
            'fbp' => ['sometimes', 'nullable', 'string'],
            'fbc' => ['sometimes', 'nullable', 'string'],
            'ttclid' => ['sometimes', 'nullable', 'string'],
            'ga_client_id' => ['sometimes', 'nullable', 'string'],
            'idempotency_key' => ['sometimes', 'nullable', 'string'],
            'occurred_at' => [
                'sometimes',
                'nullable',
                'date',
                'before_or_equal:+'.self::MAX_FUTURE_SKEW_MINUTES.' minutes',
                // See MAX_PAST_DAYS doc comment — only enforced when a gclid
                // is present, since it exists to bound Google Ads click-report
                // matching, not events in general. A whitespace-only gclid is
                // treated as absent here too (via filled()'s trim), matching
                // how TrackingEventData normalizes it downstream.
                Rule::when(
                    static fn (Fluent $input): bool => filled($input->get('gclid')),
                    ['after_or_equal:-'.self::MAX_PAST_DAYS.' days'],
                ),
            ],
        ];
    }

    /** Convenience accessor — avoids raw string keys scattered across the codebase. */
    public function shopDomain(): string
    {
        return $this->string('shop_domain')->toString();
    }

    /** Convenience accessor — avoids raw string keys scattered across the codebase. */
    public function trackingSecret(): string
    {
        return $this->string('tracking_secret')->toString();
    }

    /**
     * Hydrate a TrackingEventData DTO from the validated request payload.
     *
     * ip() and userAgent() are sourced from HTTP headers, not the body,
     * so they cannot be validated via rules() and are added here.
     */
    public function toTrackingEventData(): TrackingEventData
    {
        $validated = $this->validated();

        $occurredAt = isset($validated['occurred_at'])
            ? new DateTimeImmutable($validated['occurred_at'])
            : new DateTimeImmutable;

        return new TrackingEventData(
            shopDomain: $validated['shop_domain'],
            event: $validated['event'],
            value: (float) ($validated['value'] ?? 0),
            currency: $validated['currency'] ?? 'CAD',
            transactionId: $validated['transaction_id'] ?? null,
            gclid: $validated['gclid'] ?? null,
            fbp: $validated['fbp'] ?? null,
            fbc: $validated['fbc'] ?? null,
            ttclid: $validated['ttclid'] ?? null,
            gaClientId: $validated['ga_client_id'] ?? null,
            ip: $this->ip(),
            userAgent: $this->userAgent(),
            idempotencyKey: $validated['idempotency_key'] ?? null,
            occurredAt: $occurredAt,
        );
    }
}
