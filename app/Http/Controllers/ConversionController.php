<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tracking\ProcessTrackingEvent;
use App\Http\Requests\TrackEventRequest;
use App\Services\ConversionAuthenticator;
use App\Services\ViewItemRateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Receives Web Pixel events from the Shopify storefront and enqueues them for processing.
 *
 * Intentionally thin: delegates validation to TrackEventRequest, authentication
 * to ConversionAuthenticator, DTO construction to the request, and processing to
 * the ProcessTrackingEvent job. The controller owns none of that logic.
 */
final class ConversionController extends Controller
{
    public function __construct(
        private readonly ConversionAuthenticator $auth,
        private readonly ViewItemRateLimiter $viewItemRateLimiter,
    ) {}

    /**
     * Accept a single tracking event from the Shopify Web Pixel.
     *
     * The endpoint is public (no Shopify session middleware) but protected
     * by per-shop tracking_secret verification inside ConversionAuthenticator.
     */
    public function track(TrackEventRequest $request): JsonResponse
    {
        $shop = $this->auth->authenticate($request->shopDomain(), $request->trackingSecret());
        $data = $request->toTrackingEventData();

        if (! $this->viewItemRateLimiter->allow($shop, $data->event, $data->ip)) {
            Log::info('ConversionController: view_item event rate limited', [
                'shop_id' => $shop->getKey(),
                'ip' => $data->ip,
                'event' => $data->event,
            ]);

            return response()->json(['ok' => true, 'rate_limited' => true], 202);
        }

        ProcessTrackingEvent::dispatch($data);

        return response()->json(['ok' => true]);
    }
}
