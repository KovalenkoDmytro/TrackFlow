<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tracking\ProcessTrackingEvent;
use App\Http\Requests\TrackEventRequest;
use App\Services\ConversionAuthenticator;
use Illuminate\Http\JsonResponse;

/**
 * Receives Web Pixel events from the Shopify storefront and enqueues them for processing.
 *
 * Intentionally thin: delegates validation to TrackEventRequest, authentication
 * to ConversionAuthenticator, DTO construction to the request, and processing to
 * the ProcessTrackingEvent job. The controller owns none of that logic.
 */
final class ConversionController extends Controller
{
    public function __construct(private readonly ConversionAuthenticator $auth) {}

    /**
     * Accept a single tracking event from the Shopify Web Pixel.
     *
     * The endpoint is public (no Shopify session middleware) but protected
     * by per-shop tracking_secret verification inside ConversionAuthenticator.
     */
    public function track(TrackEventRequest $request): JsonResponse
    {
        $this->auth->authenticate($request->shopDomain(), $request->trackingSecret());

        ProcessTrackingEvent::dispatch($request->toTrackingEventData());

        return response()->json(['ok' => true]);
    }
}
