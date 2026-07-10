<?php

declare(strict_types=1);

namespace App\Actions\Pixel;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

class SyncWebPixel
{
    use AsJob, AsObject;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public int $timeout = 60;

    /**
     * Enable or disable the Shopify Web Pixel for the shop.
     *
     * A Web Pixel has no "enabled/disabled" state in Shopify — its existence *is*
     * activity. Enabling therefore creates (or updates) the pixel; disabling
     * deletes it via `webPixelDelete` so tracking genuinely stops.
     *
     * @throws RuntimeException when the requested state could not be applied
     */
    public function handle(User $shop, bool $enable): void
    {
        if ($enable) {
            $this->enablePixel($shop);
        } else {
            $this->disablePixel($shop);
        }
    }

    private function enablePixel(User $shop): void
    {
        $trackingSecret = $shop->tracking_secret;

        if (empty($trackingSecret)) {
            Log::warning('SyncWebPixel: shop has no tracking_secret', ['shop' => $shop->name]);

            throw new RuntimeException('Shop is missing a tracking secret.');
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

        $shop->pixel_enabled = true;
        $shop->save();
    }

    private function disablePixel(User $shop): void
    {
        if (empty($shop->shopify_pixel_id)) {
            // Nothing to delete on Shopify's side — just make sure local state is consistent.
            $shop->pixel_enabled = false;
            $shop->save();

            return;
        }

        $mutation = <<<'GQL'
            mutation webPixelDelete($id: ID!) {
                webPixelDelete(id: $id) {
                    deletedWebPixelId
                    userErrors { field message }
                }
            }
        GQL;

        $response = $shop->api()->graph($mutation, ['id' => $shop->shopify_pixel_id]);

        $errors = $response['body']['data']['webPixelDelete']['userErrors'] ?? [];

        if (! empty($errors)) {
            $errorsArray = json_decode(json_encode($errors), true);
            $alreadyGone = collect($errorsArray)->contains(
                fn (array $e) => str_contains(strtolower($e['message'] ?? ''), 'not found')
                    || str_contains(strtolower($e['message'] ?? ''), 'does not exist')
                    || str_contains(strtolower($e['message'] ?? ''), 'no longer exist')
            );

            if (! $alreadyGone) {
                Log::error('SyncWebPixel: webPixelDelete userErrors', [
                    'shop' => $shop->name,
                    'errors' => $errors,
                ]);

                throw new RuntimeException('Failed to delete the Shopify Web Pixel.');
            }

            // Merchant likely removed the pixel manually via Shopify admin — treat as
            // already disabled rather than getting the shop stuck retrying a delete
            // that will never succeed.
            Log::info('SyncWebPixel: pixel already deleted on Shopify side, syncing local state', [
                'shop' => $shop->name,
            ]);
        } else {
            Log::info('SyncWebPixel: pixel deleted', ['shop' => $shop->name]);
        }

        $shop->shopify_pixel_id = null;
        $shop->pixel_enabled = false;
        $shop->save();
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
            $errorsArray = json_decode(json_encode($errors), true);
            $alreadyExists = collect($errorsArray)->contains(
                fn (array $e) => str_contains(strtolower($e['message'] ?? ''), 'already exists')
                    || str_contains(strtolower($e['message'] ?? ''), 'already been set')
            );

            if ($alreadyExists) {
                // Pixel already exists — fetch its ID and save it
                Log::info('SyncWebPixel: pixel already exists, fetching ID', ['shop' => $shop->name]);
                $this->fetchAndSaveExistingPixel($shop);

                return;
            }

            Log::error('SyncWebPixel: webPixelCreate userErrors', [
                'shop' => $shop->name,
                'errors' => $errors,
            ]);

            throw new RuntimeException('Failed to create the Shopify Web Pixel.');
        }

        $pixelId = $response['body']['data']['webPixelCreate']['webPixel']['id'] ?? null;

        if ($pixelId === null) {
            Log::error('SyncWebPixel: no pixel ID in response', [
                'shop' => $shop->name,
                'body' => $response['body'] ?? null,
            ]);

            throw new RuntimeException('Shopify did not return a pixel ID.');
        }

        $shop->shopify_pixel_id = $pixelId;
        $shop->save();
        Log::info('SyncWebPixel: pixel created', ['shop' => $shop->name, 'pixel_id' => $pixelId]);
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

        if ($pixelId === null) {
            Log::error('SyncWebPixel: could not fetch existing pixel', [
                'shop' => $shop->name,
                'body' => $response['body'] ?? null,
            ]);

            throw new RuntimeException('Could not fetch the existing Shopify Web Pixel.');
        }

        $shop->shopify_pixel_id = $pixelId;
        $shop->save();
        Log::info('SyncWebPixel: saved existing pixel ID', ['shop' => $shop->name, 'pixel_id' => $pixelId]);
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

            throw new RuntimeException('Failed to update the Shopify Web Pixel.');
        }
    }
}
