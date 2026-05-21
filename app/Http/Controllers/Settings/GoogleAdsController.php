<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\GoogleAds\CreateConversionActions;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\PlatformIntegration;
use App\Services\GoogleAdsClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Google Ads platform integration controller.
 *
 * Handles the full lifecycle of a merchant's Google Ads connection:
 * displaying the settings form, persisting credentials after validation,
 * and disconnecting the integration.
 *
 * Each platform has its own controller so platform-specific form fields,
 * validation rules, and credential shapes never bleed into each other.
 */
final class GoogleAdsController extends Controller
{
    /**
     * Display the Google Ads settings form.
     *
     * Loads the existing integration (if any) and decrypts credentials
     * so the form can be pre-filled. Eager-loads conversionActionMappings
     * to show provisioned conversion actions below the form.
     */
    public function show(Request $request): View
    {
        $shop = $request->user();

        $integration = $shop->platformIntegrations()
            ->where('platform', Platform::GoogleAds)
            ->with('conversionActionMappings')
            ->first();

        $credentials = json_decode($integration?->credentials ?? '{}', true) ?? [];

        return view('settings.google-ads', compact('shop', 'integration', 'credentials'));
    }

    /**
     * Validate credentials and persist the Google Ads integration.
     *
     * Calls GoogleAdsClient::testCredentials() before saving — the merchant
     * sees an error immediately if the credentials are wrong rather than
     * discovering it when the first conversion fires.
     * Dispatches CreateConversionActions as a background job on success.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'string', 'regex:/^\d{3}-?\d{3}-?\d{4}$/'],
            'developer_token' => ['required', 'string', 'min:10'],
            'mcc_id' => ['nullable', 'string', 'regex:/^\d{3}-?\d{3}-?\d{4}$/'],
            'oauth_client_id' => ['required', 'string'],
            'oauth_client_secret' => ['required', 'string'],
            'oauth_refresh_token' => ['required', 'string'],
        ]);

        $shop = $request->user();

        $customerId = str_replace('-', '', $validated['customer_id']);
        $mccId = isset($validated['mcc_id']) ? str_replace('-', '', $validated['mcc_id']) : null;

        $credentials = [
            'customer_id' => $customerId,
            'developer_token' => $validated['developer_token'],
            'mcc_id' => $mccId,
            'oauth' => [
                'client_id' => $validated['oauth_client_id'],
                'client_secret' => $validated['oauth_client_secret'],
                'refresh_token' => $validated['oauth_refresh_token'],
            ],
        ];

        try {
            app(GoogleAdsClient::class)->testCredentials($credentials);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['credentials' => 'Could not connect to Google Ads: '.$e->getMessage()]);
        }

        $integration = PlatformIntegration::updateOrCreate(
            [
                'user_id' => $shop->getKey(),
                'platform' => Platform::GoogleAds,
            ],
            [
                'active' => true,
                'credentials' => json_encode($credentials),
                'settings' => [],
            ],
        );

        CreateConversionActions::dispatch($integration);

        session()->flash('success', 'Google Ads connected. Conversion actions are being created in the background.');

        return redirect()->route(app()->isLocal() ? 'dev.home' : 'home');
    }

    /**
     * Deactivate the Google Ads integration.
     *
     * Sets active = false rather than deleting so ConversionActionMapping
     * records are preserved and can be reactivated if the merchant reconnects.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $shop = $request->user();

        $shop->platformIntegrations()
            ->where('platform', Platform::GoogleAds)
            ->update(['active' => false]);

        session()->flash('success', 'Google Ads disconnected.');

        return redirect()->route(app()->isLocal() ? 'dev.home' : 'home');
    }
}
