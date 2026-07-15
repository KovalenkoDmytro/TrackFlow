<?php

declare(strict_types=1);

use App\Services\Shopify\LoggingApiHelper;
use Gnikyt\BasicShopifyAPI\BasicShopifyAPI;
use Gnikyt\BasicShopifyAPI\Options;
use Gnikyt\BasicShopifyAPI\ResponseAccess;
use Gnikyt\BasicShopifyAPI\Session;
use Illuminate\Support\Facades\Log;
use Osiset\ShopifyApp\Objects\Enums\AuthMode;

if (! function_exists('shopifyApiOptions')) {
    function shopifyApiOptions(): Options
    {
        $options = new Options;
        $options->setApiKey('test-api-key');
        $options->setApiSecret('test-api-secret');

        return $options;
    }
}

describe('LoggingApiHelper::performOfflineTokenExchange', function (): void {
    it('logs the exception with context and rethrows it, rather than swallowing it', function (): void {
        $exception = new RuntimeException('token exchange transport failure');

        $api = Mockery::mock(BasicShopifyAPI::class);
        $api->shouldReceive('getOptions')->andReturn(shopifyApiOptions());
        $api->shouldReceive('getSession')->andReturn(new Session('test-shop.myshopify.com'));
        $api->shouldReceive('request')->once()->andThrow($exception);

        Log::shouldReceive('error')
            ->once()
            ->with('shopify.auth.token_exchange_failed', [
                'exception' => RuntimeException::class,
                'message' => 'token exchange transport failure',
            ]);

        $helper = (new LoggingApiHelper)->setApi($api);

        expect(fn () => $helper->performOfflineTokenExchange('fresh-id-token'))
            ->toThrow(RuntimeException::class, 'token exchange transport failure');
    });

    it('does not log anything and returns the parent result when no exception occurs', function (): void {
        $api = Mockery::mock(BasicShopifyAPI::class);
        $api->shouldReceive('getOptions')->andReturn(shopifyApiOptions());
        $api->shouldReceive('getSession')->andReturn(new Session('test-shop.myshopify.com'));
        $api->shouldReceive('request')->once()->andReturn([
            'errors' => false,
            'body' => new ResponseAccess(['access_token' => 'new-token']),
        ]);

        Log::shouldReceive('error')->never();

        $helper = (new LoggingApiHelper)->setApi($api);
        $result = $helper->performOfflineTokenExchange('fresh-id-token');

        expect($result['access_token'])->toBe('new-token');
    });
});

describe('LoggingApiHelper::getAccessData', function (): void {
    it('logs the exception with context and rethrows it, rather than swallowing it', function (): void {
        config(['shopify-app.expiring_offline_tokens' => false]);

        $exception = new RuntimeException('code exchange transport failure');

        $api = Mockery::mock(BasicShopifyAPI::class);
        $api->shouldReceive('getSession')->andReturn(new Session('test-shop.myshopify.com'));
        $api->shouldReceive('requestAccess')->once()->with('legacy-oauth-code')->andThrow($exception);

        Log::shouldReceive('error')
            ->once()
            ->with('shopify.auth.code_exchange_failed', [
                'exception' => RuntimeException::class,
                'message' => 'code exchange transport failure',
            ]);

        $helper = (new LoggingApiHelper)->setApi($api);

        expect(fn () => $helper->getAccessData('legacy-oauth-code', AuthMode::OFFLINE()))
            ->toThrow(RuntimeException::class, 'code exchange transport failure');
    });

    it('does not log anything and returns the parent result when no exception occurs', function (): void {
        config(['shopify-app.expiring_offline_tokens' => false]);

        $api = Mockery::mock(BasicShopifyAPI::class);
        $api->shouldReceive('getSession')->andReturn(new Session('test-shop.myshopify.com'));
        $api->shouldReceive('requestAccess')->once()->with('legacy-oauth-code')->andReturn(
            new ResponseAccess(['access_token' => 'legacy-token']),
        );

        Log::shouldReceive('error')->never();

        $helper = (new LoggingApiHelper)->setApi($api);
        $result = $helper->getAccessData('legacy-oauth-code', AuthMode::OFFLINE());

        expect($result['access_token'])->toBe('legacy-token');
    });
});

describe('LoggingApiHelper::refreshOfflineAccessToken', function (): void {
    it('logs the exception with context and rethrows it, rather than swallowing it', function (): void {
        $exception = new RuntimeException('token refresh transport failure');

        $api = Mockery::mock(BasicShopifyAPI::class);
        $api->shouldReceive('getOptions')->andReturn(shopifyApiOptions());
        $api->shouldReceive('request')->once()->andThrow($exception);

        Log::shouldReceive('error')
            ->once()
            ->with('shopify.auth.token_refresh_failed', [
                'exception' => RuntimeException::class,
                'message' => 'token refresh transport failure',
            ]);

        $helper = (new LoggingApiHelper)->setApi($api);

        expect(fn () => $helper->refreshOfflineAccessToken('stale-refresh-token'))
            ->toThrow(RuntimeException::class, 'token refresh transport failure');
    });

    it('does not log anything and returns the parent result when no exception occurs', function (): void {
        $api = Mockery::mock(BasicShopifyAPI::class);
        $api->shouldReceive('getOptions')->andReturn(shopifyApiOptions());
        $api->shouldReceive('request')->once()->andReturn([
            'errors' => false,
            'body' => new ResponseAccess(['access_token' => 'refreshed-token']),
        ]);

        Log::shouldReceive('error')->never();

        $helper = (new LoggingApiHelper)->setApi($api);
        $result = $helper->refreshOfflineAccessToken('stale-refresh-token');

        expect($result['access_token'])->toBe('refreshed-token');
    });
});

describe('LoggingApiHelper::exchangeNonExpiringOfflineTokenForExpiring', function (): void {
    // Unlike the other methods above, the parent implementation calls
    // `$this->make(new Session(...))` internally, which replaces `$this->api`
    // with a brand new BasicShopifyAPI instance built from
    // `shopify-app.api_init` (a user-definable factory closure) when that
    // config is set — so pointing it at our mock lets `make()` run for real
    // while still exercising the mocked API underneath.
    it('logs the exception with context and rethrows it, rather than swallowing it', function (): void {
        $exception = new RuntimeException('token migration transport failure');

        $api = Mockery::mock(BasicShopifyAPI::class);
        $api->shouldReceive('setSession')->once();
        $api->shouldReceive('getOptions')->andReturn(shopifyApiOptions());
        $api->shouldReceive('request')->once()->andThrow($exception);

        config(['shopify-app.api_init' => fn () => $api]);

        Log::shouldReceive('error')
            ->once()
            ->with('shopify.auth.token_migration_failed', [
                'exception' => RuntimeException::class,
                'message' => 'token migration transport failure',
            ]);

        $helper = new LoggingApiHelper;

        expect(fn () => $helper->exchangeNonExpiringOfflineTokenForExpiring(
            'test-shop.myshopify.com',
            'non-expiring-offline-token',
        ))->toThrow(RuntimeException::class, 'token migration transport failure');
    });

    it('does not log anything and returns the parent result when no exception occurs', function (): void {
        $api = Mockery::mock(BasicShopifyAPI::class);
        $api->shouldReceive('setSession')->once();
        $api->shouldReceive('getOptions')->andReturn(shopifyApiOptions());
        $api->shouldReceive('request')->once()->andReturn([
            'errors' => false,
            'body' => new ResponseAccess(['access_token' => 'expiring-token']),
        ]);

        config(['shopify-app.api_init' => fn () => $api]);

        Log::shouldReceive('error')->never();

        $helper = new LoggingApiHelper;
        $result = $helper->exchangeNonExpiringOfflineTokenForExpiring(
            'test-shop.myshopify.com',
            'non-expiring-offline-token',
        );

        expect($result['access_token'])->toBe('expiring-token');
    });
});
