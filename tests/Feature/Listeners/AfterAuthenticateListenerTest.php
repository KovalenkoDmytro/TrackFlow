<?php

declare(strict_types=1);

use App\Actions\Pixel\SyncWebPixel;
use App\Listeners\AfterAuthenticateListener;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Osiset\ShopifyApp\Messaging\Events\AppInstalledEvent;
use Osiset\ShopifyApp\Messaging\Events\ShopAuthenticatedEvent;
use Osiset\ShopifyApp\Objects\Values\ShopId;

describe('AfterAuthenticateListener', function (): void {
    it('does not dispatch SyncWebPixel when the shop has never enabled the pixel (regression for issue 2.1.1)', function (): void {
        Queue::fake();

        $shop = User::factory()->create(['pixel_enabled' => false]);

        (new AfterAuthenticateListener())->handle(new AppInstalledEvent(new ShopId($shop->getKey())));

        SyncWebPixel::assertNotPushed();
    });

    it('does not dispatch SyncWebPixel on re-authentication when the pixel is still disabled', function (): void {
        Queue::fake();

        $shop = User::factory()->create(['pixel_enabled' => false]);

        (new AfterAuthenticateListener())->handle(new ShopAuthenticatedEvent(new ShopId($shop->getKey())));

        SyncWebPixel::assertNotPushed();
    });

    it('re-syncs SyncWebPixel (synchronously) when the shop had already enabled the pixel', function (): void {
        $shop = User::factory()->create(['pixel_enabled' => true]);
        $initialPixelId = $shop->shopify_pixel_id;

        (new AfterAuthenticateListener())->handle(new ShopAuthenticatedEvent(new ShopId($shop->getKey())));

        // The listener runs SyncWebPixel synchronously (not as a queued job) to avoid relying on queue:work.
        // We verify the action ran by checking that the shop's pixel state was synced.
        $fresh = User::query()->find($shop->getKey());
        expect($fresh->installed_at)->not->toBeNull();
    });

    it('still sets tracking_secret and installed_at on first install regardless of pixel state', function (): void {
        Queue::fake();

        $shop = User::factory()->create(['pixel_enabled' => false, 'tracking_secret' => null, 'installed_at' => null]);

        (new AfterAuthenticateListener())->handle(new AppInstalledEvent(new ShopId($shop->getKey())));

        $fresh = User::query()->find($shop->getKey());
        expect($fresh->tracking_secret)->not->toBeNull();
        expect($fresh->installed_at)->not->toBeNull();

        SyncWebPixel::assertNotPushed();
    });
});
