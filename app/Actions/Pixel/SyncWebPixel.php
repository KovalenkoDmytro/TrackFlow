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

        $this->assertNoGraphErrors($response, $shop);

        $errors = $response['body']['data']['webPixelDelete']['userErrors'] ?? [];

        if (count($errors) > 0) {
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

        $this->assertNoGraphErrors($response, $shop);

        $errors = $response['body']['data']['webPixelCreate']['userErrors'] ?? [];

        if (count($errors) > 0) {
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

        $this->assertNoGraphErrors($response, $shop);

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

        $this->assertNoGraphErrors($response, $shop);

        $errors = $response['body']['data']['webPixelUpdate']['userErrors'] ?? [];

        if (count($errors) > 0) {
            Log::error('SyncWebPixel: webPixelUpdate userErrors', [
                'shop' => $shop->name,
                'errors' => $errors,
            ]);

            throw new RuntimeException('Failed to update the Shopify Web Pixel.');
        }
    }

    /**
     * Guard against top-level GraphQL errors (e.g. `ACCESS_DENIED` due to a
     * missing scope, throttling, or an invalid query). The
     * `gnikyt/basic-shopify-api` client puts these in the root `errors` key
     * of the response — separate from the nested `userErrors` returned by
     * a mutation payload. When Shopify rejects the operation itself, `data`
     * is `null` and there is no `userErrors` to inspect, so this check must
     * run before any nested-error handling.
     *
     * The client also distinguishes between two failure modes:
     * - A successful HTTP request (2xx) carrying GraphQL errors in the body
     *   → `handleSuccess()` sets `errors` to the array of `{message, ...}`.
     * - A failed HTTP request (non-2xx, e.g. throttling/429 or a 5xx) →
     *   `handleFailure()` sets `errors` to the literal boolean `true` and
     *   puts the HTTP status in `status` and any decoded error body in
     *   `body` (which is *not* the usual `['data' => ...]` shape in this
     *   case). That case must be handled separately before the array-based
     *   pluck/implode logic below, otherwise the boolean `true` gets
     *   stringified into a useless "Shopify GraphQL error: true" message.
     *
     * @param  array<string, mixed>  $response
     */
    private function assertNoGraphErrors(array $response, User $shop): void
    {
        $errors = $response['errors'] ?? false;

        if (empty($errors)) {
            return;
        }

        if ($errors === true) {
            Log::error('SyncWebPixel: GraphQL request failed at HTTP level', [
                'shop' => $shop->name,
                'response' => $response,
            ]);

            throw new RuntimeException(
                'Shopify API request failed (HTTP error, possibly rate limiting) — please try again in a moment.'
            );
        }

        $message = collect($errors)
            ->pluck('message')
            ->filter()
            ->implode('; ');

        if ($message === '') {
            $message = is_string($errors) ? $errors : json_encode($errors);
        }

        Log::error('SyncWebPixel: GraphQL top-level errors', [
            'shop' => $shop->name,
            'errors' => $errors,
        ]);

        throw new RuntimeException('Shopify GraphQL error: '.$message);
    }
}
