<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Osiset\ShopifyApp\Messaging\Events\AppInstalledEvent;

final class AfterAuthenticateListener
{
    public function handle(AppInstalledEvent $event): void
    {
        $shop = User::query()->find($event->shopId->toNative());

        if (! $shop instanceof User) {
            Log::warning('AfterAuthenticateListener: shop not found', ['shop_id' => $event->shopId->toNative()]);

            return;
        }

        $trackingSecret = bin2hex(random_bytes(16));

        $shop->tracking_secret = $trackingSecret;
        $shop->installed_at = now();
        $shop->save();

        $this->createWebPixel($shop, $trackingSecret);
    }

    private function createWebPixel(User $shop, string $trackingSecret): void
    {
        $mutation = <<<'GQL'
            mutation webPixelCreate($input: WebPixelInput!) {
                webPixelCreate(webPixel: $input) {
                    webPixel {
                        id
                        settings
                    }
                    userErrors { field message }
                }
            }
        GQL;

        $variables = [
            'input' => [
                'settings' => json_encode([
                    'tracking_secret' => $trackingSecret,
                    'api_url' => config('app.url').'/api/conversions',
                ], JSON_THROW_ON_ERROR),
            ],
        ];

        try {
            $response = $shop->api()->graph($mutation, $variables);

            $errors = $response['body']['data']['webPixelCreate']['userErrors'] ?? [];

            if (! empty($errors)) {
                Log::error('AfterAuthenticateListener: webPixelCreate userErrors', [
                    'shop' => $shop->name,
                    'errors' => $errors,
                ]);

                return;
            }

            $pixelId = $response['body']['data']['webPixelCreate']['webPixel']['id'] ?? null;

            if ($pixelId !== null) {
                $shop->shopify_pixel_id = $pixelId;
                $shop->save();
            }
        } catch (\Throwable $e) {
            Log::error('AfterAuthenticateListener: webPixelCreate failed', [
                'shop' => $shop->name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
