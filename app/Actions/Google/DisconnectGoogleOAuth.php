<?php

declare(strict_types=1);

namespace App\Actions\Google;

use App\Models\OauthCredential;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * Revokes the single shared Google OAuth credential used by GA4 Data API
 * reporting. The refresh_token is nulled out (not just marked revoked) so it
 * can never be decrypted or reused after disconnect, matching the intent of
 * PlatformIntegration-style disconnects elsewhere in the app.
 */
final class DisconnectGoogleOAuth
{
    use AsController;

    public function handle(): RedirectResponse
    {
        OauthCredential::query()
            ->where('provider', 'google')
            ->update([
                'revoked_at' => now(),
                'refresh_token' => null,
            ]);

        return redirect('/')->with('status', 'Google account disconnected.');
    }
}
