<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Pixel\SyncWebPixel;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Osiset\ShopifyApp\Messaging\Events\AppInstalledEvent;
use Osiset\ShopifyApp\Messaging\Events\ShopAuthenticatedEvent;

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

        // Dispatch pixel sync as a background job — never block the OAuth callback
        SyncWebPixel::dispatch($shop);
    }
}
