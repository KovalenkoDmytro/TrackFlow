<?php

declare(strict_types=1);

namespace App\Actions\Google;

use App\Models\OauthCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * Exchanges the `code` Google sends back to /operator/google/callback for a
 * refresh_token and upserts it into the single shared OauthCredential row
 * (provider "google") used by GA4 Data API reporting.
 *
 * Google only returns a refresh_token on the *first* consent grant for a
 * given client/account pair unless `prompt=consent` was sent (see
 * StartGoogleOAuth) — if it is ever missing here despite that, this fails
 * loudly with a flashed error instead of silently leaving the existing
 * refresh_token (or none at all) in place.
 *
 * Before exchanging the code, the `state` query param Google echoes back is
 * validated against the value StartGoogleOAuth stashed in the session. This
 * prevents an attacker from starting their own consent flow, obtaining a
 * `code` for their own Google account, and luring an authenticated operator
 * into opening this callback URL with that code — which would otherwise
 * overwrite the single shared OauthCredential row with the attacker's
 * refresh_token. The session value is forgotten immediately after the check
 * (success or failure) so it can never be replayed.
 */
final class HandleGoogleOAuthCallback
{
    use AsController;

    public function handle(Request $request): RedirectResponse
    {
        $expectedState = session()->pull('google_oauth_state');
        $actualState = $request->query('state');

        $stateIsValid = is_string($expectedState)
            && $expectedState !== ''
            && is_string($actualState)
            && hash_equals($expectedState, $actualState);

        if (! $stateIsValid) {
            Log::warning('HandleGoogleOAuthCallback: state mismatch, rejecting callback');

            return redirect('/')->with('error', 'Google OAuth request could not be verified. Please try connecting again.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return redirect('/')->with('error', 'Google did not return an authorization code.');
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        $body = $response->json();

        if (! $response->successful()) {
            $message = is_array($body) ? ($body['error_description'] ?? $body['error'] ?? json_encode($body)) : $response->body();
            Log::error('HandleGoogleOAuthCallback: token exchange failed', ['error' => $message]);

            return redirect('/')->with('error', "Google OAuth token exchange failed: {$message}");
        }

        $refreshToken = is_array($body) ? ($body['refresh_token'] ?? null) : null;

        if (! is_string($refreshToken) || $refreshToken === '') {
            return redirect('/')->with(
                'error',
                'Google did not return a refresh token. Revoke this app\'s access at '
                .'https://myaccount.google.com/permissions and try connecting again.',
            );
        }

        $scopes = is_array($body) && is_string($body['scope'] ?? null)
            ? explode(' ', $body['scope'])
            : [];

        OauthCredential::query()->updateOrCreate(
            ['provider' => 'google'],
            [
                'refresh_token' => $refreshToken,
                'scopes' => $scopes,
                'connected_by' => $request->user()?->getKey(),
                'connected_at' => now(),
                'revoked_at' => null,
            ],
        );

        return redirect('/')->with('status', 'Google account connected successfully.');
    }
}
