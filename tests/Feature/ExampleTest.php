<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // The homepage requires Shopify embedded-launch verification
        // (App\Http\Middleware\VerifyShopifyEmbeddedLaunch). Unverified
        // requests are redirected to the session-token bounce page.
        $response = $this->get('/');

        $response->assertStatus(302);
    }
}
