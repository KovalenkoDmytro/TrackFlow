<?php

declare(strict_types=1);

namespace App\Actions\Google;

use App\Enums\Platform;
use App\Models\PlatformIntegration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * Exchanges the `code` Google sends back to /settings/ga4/google/callback for
 * a refresh_token and merges it into the AUTHENTICATED SHOP's own
 * PlatformIntegration (platform GoogleAnalytics4) credentials.
 *
 * Every shop connects its own Google account — there is no shared/global
 * credential. Only the `oauth_refresh_token` key is written; any existing
 * `measurement_id`, `api_secret`, and `property_id` already stored for this
 * shop are preserved untouched.
 *
 * Before exchanging the code, the `state` query param Google echoes back is
 * validated against the value StartShopGoogleOAuth stashed in the session.
 * This prevents an attacker from starting their own consent flow, obtaining a
 * `code` for their own Google account, and luring an authenticated shop into
 * opening this callback URL with that code — which would otherwise link the
 * attacker's Google account to the merchant's PlatformIntegration row
 * (authorization-code / login CSRF). The session value is forgotten
 * immediately after the check (success or failure) so it can never be
 * replayed.
 *
 * Google only returns a refresh_token on the *first* consent grant for a
 * given client/account pair unless `prompt=consent` was sent (see
 * StartShopGoogleOAuth, which always sends it). If Google still omits it —
 * e.g. the merchant re-clicks "Connect with Google" after already having
 * granted offline access without revoking it — the existing stored
 * refresh_token (if any) is left in place and the flow is treated as
 * success. Only a shop with no refresh_token at all (neither returned nor
 * already stored) sees an error.
 */
final class HandleShopGoogleOAuthCallback
{
    use AsController;

    public function handle(Request $request): RedirectResponse
    {
        $expectedState = session()->pull('ga4_google_oauth_state');
        $actualState = $request->query('state');

        $stateIsValid = is_string($expectedState)
            && $expectedState !== ''
            && is_string($actualState)
            && hash_equals($expectedState, $actualState);

        if (! $stateIsValid) {
            Log::warning('HandleShopGoogleOAuthCallback: state mismatch, rejecting callback');

            return $this->redirectToGa4Settings(error: 'Google OAuth request could not be verified. Please try connecting again.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->redirectToGa4Settings(error: 'Google did not return an authorization code.');
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
            Log::error('HandleShopGoogleOAuthCallback: token exchange failed', [
                'status' => $response->status(),
            ]);

            return $this->redirectToGa4Settings(error: 'Google OAuth token exchange failed. Please try connecting again.');
        }

        $refreshToken = is_array($body) ? ($body['refresh_token'] ?? null) : null;

        $user = $request->user();

        $integration = PlatformIntegration::query()
            ->where('user_id', $user->getKey())
            ->where('platform', Platform::GoogleAnalytics4)
            ->first();

        /** @var array<string, mixed> $credentials */
        $credentials = $integration?->credentials !== null
            ? (json_decode((string) $integration->credentials, true) ?? [])
            : [];

        $hasStoredToken = ! empty($credentials['oauth_refresh_token']);

        if (! is_string($refreshToken) || $refreshToken === '') {
            if (! $hasStoredToken) {
                return $this->redirectToGa4Settings(
                    error: 'Google did not return a refresh token. Revoke this app\'s access at '
                        .'https://myaccount.google.com/permissions and try connecting again.',
                );
            }

            // Merchant already had a refresh_token stored — nothing to update.
            return $this->redirectToGa4Settings(status: 'connected');
        }

        $credentials['oauth_refresh_token'] = $refreshToken;

        if ($integration !== null) {
            $integration->update(['credentials' => json_encode($credentials)]);
        } else {
            PlatformIntegration::query()->create([
                'user_id' => $user->getKey(),
                'platform' => Platform::GoogleAnalytics4,
                'active' => false,
                'credentials' => json_encode($credentials),
                'settings' => [],
            ]);
        }

        return $this->redirectToGa4Settings(status: 'connected');
    }

    private function redirectToGa4Settings(?string $status = null, ?string $error = null): RedirectResponse
    {
        $params = array_filter([
            'google' => $status,
            'google_error' => $error,
        ]);

        return redirect('/settings/ga4'.($params !== [] ? '?'.http_build_query($params) : ''));
    }
}
