<?php

declare(strict_types=1);

namespace App\Actions\Google;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * Redirects the authenticated shop to Google's OAuth consent screen so it can
 * connect its OWN Google account for GA4 Admin API access (auto-creating Key
 * Events in the merchant's own GA4 property).
 *
 * Every shop connects its own Google account through the app's single OAuth
 * client (config('services.google.*')) — there is no shared/global Google
 * account. The resulting refresh_token is stored on the authenticated shop's
 * own PlatformIntegration row by HandleShopGoogleOAuthCallback.
 *
 * `access_type=offline` + `prompt=consent` guarantee Google returns a
 * refresh_token even if this shop previously granted consent — without
 * `prompt=consent`, a returning merchant re-clicking "Connect with Google"
 * would only get an access token back.
 *
 * A random `state` value is generated and stashed in the session, then sent
 * as the `state` query param on the authorize URL. HandleShopGoogleOAuthCallback
 * validates the `state` Google echoes back against this session value before
 * exchanging the code — without this, an attacker could start their own
 * consent flow, get a `code` for their own Google account, and lure an
 * authenticated shop into opening the callback URL with that code, linking
 * the attacker's Google account to the merchant's PlatformIntegration row
 * (authorization-code / login CSRF).
 *
 * This route is a plain `auth:web` route — any authenticated shop may use it
 * for itself, no extra authorization is required.
 */
final class StartShopGoogleOAuth
{
    use AsController;

    public function handle(): RedirectResponse
    {
        $state = Str::random(40);

        session(['ga4_google_oauth_state' => $state]);

        $query = [
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'scope' => implode(' ', config('services.google.scopes', [])),
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'state' => $state,
        ];

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($query));
    }
}
