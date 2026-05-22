<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class SetupPixelCommand extends Command
{
    protected $signature = 'trackflow:setup-pixel {shop? : myshopify domain, e.g. test.myshopify.com}';

    protected $description = 'Create or update the Shopify Web Pixel for shops that are missing one';

    public function handle(): int
    {
        $shopDomain = $this->argument('shop');

        $query = User::query()->whereNull('shopify_pixel_id');

        if ($shopDomain !== null) {
            $query->where('name', $shopDomain);
        }

        $shops = $query->get();

        if ($shops->isEmpty()) {
            $this->info('No shops need pixel setup.');

            return self::SUCCESS;
        }

        foreach ($shops as $shop) {
            $this->processShop($shop);
        }

        return self::SUCCESS;
    }

    private function processShop(User $shop): void
    {
        $this->info("Processing: {$shop->name}");

        // Ensure tracking_secret exists
        $trackingSecret = $shop->tracking_secret;
        if (empty($trackingSecret)) {
            $trackingSecret = bin2hex(random_bytes(16));
            $shop->tracking_secret = $trackingSecret;
            $shop->save();
            $this->line('  Generated new tracking_secret');
        }

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
                    'tracking_secret' => (string) $trackingSecret,
                    'api_url' => config('app.url').'/api/conversions',
                ], JSON_THROW_ON_ERROR),
            ],
        ];

        try {
            $response = $shop->api()->graph($mutation, $variables);

            $errors = $response['body']['data']['webPixelCreate']['userErrors'] ?? [];

            if (! empty($errors)) {
                $this->error('  userErrors: '.json_encode($errors));

                return;
            }

            $pixelId = $response['body']['data']['webPixelCreate']['webPixel']['id'] ?? null;

            if ($pixelId !== null) {
                $shop->shopify_pixel_id = $pixelId;
                $shop->save();
                $this->info("  Pixel created: {$pixelId}");
            } else {
                $this->warn('  No pixel ID in response. Full response:');
                $this->line(json_encode($response['body'] ?? $response, JSON_PRETTY_PRINT));
            }
        } catch (\Throwable $e) {
            $this->error("  Exception: {$e->getMessage()}");
            Log::error('SetupPixelCommand: webPixelCreate failed', [
                'shop' => $shop->name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
