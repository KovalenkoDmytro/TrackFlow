<?php

declare(strict_types=1);

namespace App\Actions\GoogleAds;

use App\Models\PlatformIntegration;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Turns a revoked Google Ads access into a quiet "needs reconnect" state.
 *
 * Reuses the existing disconnect mechanism: active = false makes click sync,
 * live uploads and every health check skip the integration, and the settings
 * UI shows it as disconnected with last_error explaining why. Saving the
 * credentials again in settings sets active = true and clears the error.
 */
final class MarkGoogleAdsAccessRevoked
{
    use AsObject;

    public const LAST_ERROR = 'Google Ads access was revoked or is missing. Reconnect Google Ads in settings.';

    public const LAST_ERROR_NO_MCC = 'Google Ads access was revoked or is missing (no manager account ID configured). Reconnect Google Ads in settings.';

    public function handle(PlatformIntegration $integration, string $source): void
    {
        $credentials = is_string($integration->credentials)
            ? json_decode($integration->credentials, true)
            : null;
        $hasMcc = is_array($credentials) && ! empty($credentials['mcc_id']);

        // Atomic guard: only the first caller flips the flag, so concurrent jobs log once.
        $changed = PlatformIntegration::query()->whereKey($integration->getKey())->where('active', true)
            ->update([
                'active' => false,
                'last_error' => $hasMcc ? self::LAST_ERROR : self::LAST_ERROR_NO_MCC,
                'last_error_at' => now(),
            ]);

        $integration->refresh();

        if ($changed === 0) {
            return;
        }

        Log::warning('Google Ads access revoked, integration deactivated until reconnected', [
            'integration' => $integration->getKey(),
            'source' => $source,
            'has_mcc_id' => $hasMcc,
        ]);
    }
}
