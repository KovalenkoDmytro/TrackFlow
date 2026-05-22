<?php

declare(strict_types=1);

namespace App\Actions\Pixel;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

class SyncWebPixel
{
    use AsJob, AsObject;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public int $timeout = 60;

    public function handle(User $shop): void
    {
        $trackingSecret = $shop->tracking_secret;

        if (empty($trackingSecret)) {
            Log::warning('SyncWebPixel: shop has no tracking_secret', ['shop' => $shop->name]);

            return;
        }

        $settings = json_encode([
            'tracking_secret' => (string) $trackingSecret,
            'api_url' => config('app.url').'/api/conversions',
        ], JSON_THROW_ON_ERROR);

        if (empty($shop->shopify_pixel_id)) {
            $this->createPixel($shop, $settings);
        } else {
            $this->updatePixel($shop, $settings);
        }
    }

    private function createPixel(User $shop, string $settings): void
    {
        $mutation = <<<'GQL'
            mutation webPixelCreate($input: WebPixelInput!) {
                webPixelCreate(webPixel: $input) {
                    webPixel { id settings }
                    userErrors { field message }
                }
            }
        GQL;

        $response = $shop->api()->graph($mutation, ['input' => ['settings' => $settings]]);

        $errors = $response['body']['data']['webPixelCreate']['userErrors'] ?? [];

        if (! empty($errors)) {
            $alreadyExists = collect($errors)->contains(
                fn (array $e) => str_contains(strtolower($e['message'] ?? ''), 'already exists')
            );

            if ($alreadyExists) {
                // Pixel already exists — fetch its ID and save it
                Log::info('SyncWebPixel: pixel already exists, fetching ID', ['shop' => $shop->name]);
                $this->fetchAndSaveExistingPixel($shop);
            } else {
                Log::error('SyncWebPixel: webPixelCreate userErrors', [
                    'shop' => $shop->name,
                    'errors' => $errors,
                ]);
            }

            return;
        }

        $pixelId = $response['body']['data']['webPixelCreate']['webPixel']['id'] ?? null;

        if ($pixelId !== null) {
            $shop->shopify_pixel_id = $pixelId;
            $shop->save();
            Log::info('SyncWebPixel: pixel created', ['shop' => $shop->name, 'pixel_id' => $pixelId]);
        } else {
            Log::error('SyncWebPixel: no pixel ID in response', [
                'shop' => $shop->name,
                'body' => $response['body'] ?? null,
            ]);
        }
    }

    private function fetchAndSaveExistingPixel(User $shop): void
    {
        $query = <<<'GQL'
            query {
                webPixel {
                    id
                    settings
                }
            }
        GQL;

        $response = $shop->api()->graph($query);

        $pixelId = $response['body']['data']['webPixel']['id'] ?? null;

        if ($pixelId !== null) {
            $shop->shopify_pixel_id = $pixelId;
            $shop->save();
            Log::info('SyncWebPixel: saved existing pixel ID', ['shop' => $shop->name, 'pixel_id' => $pixelId]);
        } else {
            Log::error('SyncWebPixel: could not fetch existing pixel', [
                'shop' => $shop->name,
                'body' => $response['body'] ?? null,
            ]);
        }
    }

    private function updatePixel(User $shop, string $settings): void
    {
        $mutation = <<<'GQL'
            mutation webPixelUpdate($id: ID!, $webPixel: WebPixelInput!) {
                webPixelUpdate(id: $id, webPixel: $webPixel) {
                    webPixel { id settings }
                    userErrors { field message }
                }
            }
        GQL;

        $response = $shop->api()->graph($mutation, [
            'id' => $shop->shopify_pixel_id,
            'webPixel' => ['settings' => $settings],
        ]);

        $errors = $response['body']['data']['webPixelUpdate']['userErrors'] ?? [];

        if (! empty($errors)) {
            Log::error('SyncWebPixel: webPixelUpdate userErrors', [
                'shop' => $shop->name,
                'errors' => $errors,
            ]);
        }
    }
}
