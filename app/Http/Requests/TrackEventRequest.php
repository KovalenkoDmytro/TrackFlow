<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\TrackingEventData;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an inbound Web Pixel tracking event payload.
 *
 * Authentication (shop lookup + tracking secret verification) is intentionally
 * NOT done here — that is the responsibility of ConversionAuthenticator.
 * This request only enforces structural rules on the incoming JSON.
 */
final class TrackEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authentication is handled by ConversionAuthenticator in the controller
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'shop_domain'     => ['required', 'string'],
            'tracking_secret' => ['required', 'string'],
            'event'           => ['required', 'string'],
            'value'           => ['sometimes', 'numeric', 'min:0'],
            'currency'        => ['sometimes', 'string', 'size:3'],
            'transaction_id'  => ['sometimes', 'nullable', 'string'],
            'gclid'           => ['sometimes', 'nullable', 'string'],
            'fbp'             => ['sometimes', 'nullable', 'string'],
            'fbc'             => ['sometimes', 'nullable', 'string'],
            'ttclid'          => ['sometimes', 'nullable', 'string'],
            'ga_client_id'    => ['sometimes', 'nullable', 'string'],
            'idempotency_key' => ['sometimes', 'nullable', 'string'],
            'occurred_at'     => ['sometimes', 'nullable', 'date'],
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
            : new DateTimeImmutable();

        return new TrackingEventData(
            shopDomain:     $validated['shop_domain'],
            event:          $validated['event'],
            value:          (float) ($validated['value'] ?? 0),
            currency:       $validated['currency'] ?? 'CAD',
            transactionId:  $validated['transaction_id'] ?? null,
            gclid:          $validated['gclid'] ?? null,
            fbp:             $validated['fbp'] ?? null,
            fbc:             $validated['fbc'] ?? null,
            ttclid:          $validated['ttclid'] ?? null,
            gaClientId:     $validated['ga_client_id'] ?? null,
            ip:              $this->ip(),
            userAgent:      $this->userAgent(),
            idempotencyKey: $validated['idempotency_key'] ?? null,
            occurredAt:     $occurredAt,
        );
    }
}
