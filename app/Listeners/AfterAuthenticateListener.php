<?php

declare(strict_types=1);

namespace App\Listeners;

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
            Log::warning('AfterAuthenticateListener: shop not found', ['shop_id' => $event->shopId->toNative()]);

            return;
        }

        // Only generate tracking_secret on first install
        $trackingSecret = $shop->tracking_secret;
        if (empty($trackingSecret)) {
            $trackingSecret = bin2hex(random_bytes(16));
            $shop->tracking_secret = $trackingSecret;
        }

        $shop->installed_at = now();
        $shop->save();

        if (empty($shop->shopify_pixel_id)) {
            $this->createWebPixel($shop, (string) $trackingSecret);
        } else {
            $this->updateWebPixel($shop, (string) $trackingSecret);
        }
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

    private function updateWebPixel(User $shop, string $trackingSecret): void
    {
        $mutation = <<<'GQL'
            mutation webPixelUpdate($id: ID!, $webPixel: WebPixelInput!) {
                webPixelUpdate(id: $id, webPixel: $webPixel) {
                    webPixel {
                        id
                        settings
                    }
                    userErrors { field message }
                }
            }
        GQL;

        $variables = [
            'id' => $shop->shopify_pixel_id,
            'webPixel' => [
                'settings' => json_encode([
                    'tracking_secret' => $trackingSecret,
                    'api_url' => config('app.url').'/api/conversions',
                ], JSON_THROW_ON_ERROR),
            ],
        ];

        try {
            $response = $shop->api()->graph($mutation, $variables);

            $errors = $response['body']['data']['webPixelUpdate']['userErrors'] ?? [];

            if (! empty($errors)) {
                Log::error('AfterAuthenticateListener: webPixelUpdate userErrors', [
                    'shop' => $shop->name,
                    'errors' => $errors,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('AfterAuthenticateListener: webPixelUpdate failed', [
                'shop' => $shop->name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
