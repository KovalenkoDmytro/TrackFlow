<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

if (! function_exists('signShopifyWebhookPayload')) {
    /**
     * Computes the base64-encoded HMAC-SHA256 signature the same way
     * Osiset\ShopifyApp\Http\Middleware\AuthWebhook verifies it, so tests
     * can produce a header that passes signature verification.
     */
    function signShopifyWebhookPayload(string $rawBody): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, (string) config('shopify-app.api_secret'), true));
    }
}
