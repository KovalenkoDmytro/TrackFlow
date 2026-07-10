<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Pixel\SyncWebPixel;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Osiset\ShopifyApp\Messaging\Events\AppInstalledEvent;
use Osiset\ShopifyApp\Messaging\Events\ShopAuthenticatedEvent;
use RuntimeException;

final class AfterAuthenticateListener
{
    public function handle(AppInstalledEvent|ShopAuthenticatedEvent $event): void
    {
        $shop = User::query()->find($event->shopId->toNative());

        if (! $shop instanceof User) {
            Log::warning('AfterAuthenticateListener: shop not found', [
                'shop_id' => $event->shopId->toNative(),
            ]);

            return;
        }

        // Only generate tracking_secret on first install
        if (empty($shop->tracking_secret)) {
            $shop->tracking_secret = bin2hex(random_bytes(16));
        }

        $shop->installed_at = now();
        $shop->save();

        // Do NOT auto-create the pixel here. Shopify App Review requires an explicit,
        // visible merchant opt-in before any tracking pixel is activated (see PixelApiController).
        // If the merchant had already enabled the pixel before this re-auth, re-sync its
        // settings (e.g. tracking_secret/api_url may have changed) without changing its state.
        //
        // Run synchronously rather than dispatching a queued job: QUEUE_CONNECTION=database
        // requires a running `queue:work` worker, which is not guaranteed in this deployment.
        // A queued dispatch here would silently never execute if no worker is running — the
        // same class of bug that caused the pixel to never activate in the first place.
        // Failures are logged and swallowed so they never block the OAuth callback.
        if ($shop->pixel_enabled) {
            try {
                SyncWebPixel::run($shop, true);
            } catch (RuntimeException $e) {
                Log::warning('AfterAuthenticateListener: pixel re-sync failed', [
                    'shop' => $shop->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
