<?php

declare(strict_types=1);

namespace App\Actions\Google;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * Redirects an operator to Google's OAuth consent screen to connect the
 * single shared Google account used by GA4 Data API reporting.
 *
 * `access_type=offline` + `prompt=consent` guarantee Google returns a
 * refresh_token even if this account previously granted consent — without
 * `prompt=consent`, a returning user would only get an access token (see
 * HandleGoogleOAuthCallback for how a missing refresh_token is surfaced).
 *
 * A random `state` value is generated and stashed in the session, then sent
 * as the `state` query param on the authorize URL. HandleGoogleOAuthCallback
 * validates the `state` Google echoes back against this session value before
 * exchanging the code — without this, an attacker could start their own
 * consent flow, get a `code` for their own Google account, and lure an
 * authenticated operator into opening the callback URL with that code,
 * overwriting the single shared OauthCredential row with the attacker's
 * refresh_token (login/account-linking CSRF).
 *
 * Route-level authorization (`can:connect-google`) restricts this to the
 * operator emails in config('services.operators') — see
 * App\Providers\AppServiceProvider::boot().
 */
final class StartGoogleOAuth
{
    use AsController;

    public function handle(): RedirectResponse
    {
        $state = Str::random(40);

        session(['google_oauth_state' => $state]);

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
