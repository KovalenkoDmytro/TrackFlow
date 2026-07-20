<?php

declare(strict_types=1);

use App\Actions\Ga4\FetchGa4Report;
use App\Models\OauthCredential;
use App\Models\ShopGa4Setting;
use App\Models\User;
use App\Services\Ga4ReportingClient;
use App\Services\Ga4TokenProvider;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

mutates(FetchGa4Report::class);

/**
 * Ga4ReportingClient is `final`, so it cannot be mocked with Mockery — the
 * real client (backed by a real Ga4TokenProvider + OauthCredential row and
 * Http::fake()) is used, and cache-hit assertions are made by counting HTTP
 * requests to the Data API rather than mock expectations.
 */
beforeEach(function (): void {
    config(['cache.stores.redis' => ['driver' => 'array']]);
});

if (! function_exists('fetchGa4Report')) {
    function fetchGa4Report(): FetchGa4Report
    {
        return new FetchGa4Report(new Ga4ReportingClient(new Ga4TokenProvider));
    }
}

describe('FetchGa4Report', function (): void {
    it('throws a 404 when the shop has no ga4Setting at all', function (): void {
        $shop = User::factory()->create();

        expect(fn () => fetchGa4Report()->handle($shop, []))->toThrow(NotFoundHttpException::class);
    });

    it('throws a 404 when the ga4Setting exists but is inactive', function (): void {
        $shop = User::factory()->create();
        ShopGa4Setting::query()->create([
            'user_id' => $shop->getKey(),
            'property_id' => '123',
            'active' => false,
        ]);

        expect(fn () => fetchGa4Report()->handle($shop, []))->toThrow(NotFoundHttpException::class);
    });

    it('runs the report against the property configured for the shop and returns the result', function (): void {
        $shop = User::factory()->create();
        ShopGa4Setting::query()->create([
            'user_id' => $shop->getKey(),
            'property_id' => '999888777',
            'active' => true,
        ]);
        OauthCredential::query()->create(['provider' => 'google', 'refresh_token' => 'stored-refresh-token']);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 3600], 200),
            'analyticsdata.googleapis.com/*' => Http::response(['rows' => ['a']], 200),
        ]);

        $result = fetchGa4Report()->handle($shop, ['dateRanges' => []]);

        expect($result)->toBe(['rows' => ['a']]);
        Http::assertSent(fn ($request) => $request->url() === 'https://analyticsdata.googleapis.com/v1beta/properties/999888777:runReport');
    });

    it('caches the result for repeated identical requests so the Data API is not re-hit', function (): void {
        $shop = User::factory()->create();
        ShopGa4Setting::query()->create([
            'user_id' => $shop->getKey(),
            'property_id' => '111',
            'active' => true,
        ]);
        OauthCredential::query()->create(['provider' => 'google', 'refresh_token' => 'stored-refresh-token']);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 3600], 200),
            'analyticsdata.googleapis.com/*' => Http::response(['rows' => ['cached']], 200),
        ]);

        $action = fetchGa4Report();
        $first = $action->handle($shop, ['dateRanges' => []]);
        $second = $action->handle($shop, ['dateRanges' => []]);

        expect($first)->toBe($second)->toBe(['rows' => ['cached']]);
        Http::assertSentCount(2); // 1 token exchange + 1 Data API call across both handle() calls.
    });

    it('re-runs the report when the requested params differ (distinct cache key)', function (): void {
        $shop = User::factory()->create();
        ShopGa4Setting::query()->create([
            'user_id' => $shop->getKey(),
            'property_id' => '111',
            'active' => true,
        ]);
        OauthCredential::query()->create(['provider' => 'google', 'refresh_token' => 'stored-refresh-token']);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 3600], 200),
            'analyticsdata.googleapis.com/*' => Http::sequence()
                ->push(['rows' => ['a']], 200)
                ->push(['rows' => ['b']], 200),
        ]);

        $action = fetchGa4Report();
        $first = $action->handle($shop, ['dateRanges' => ['x']]);
        $second = $action->handle($shop, ['dateRanges' => ['y']]);

        expect($first)->not->toBe($second);
        Http::assertSentCount(3); // 1 token exchange (cached across calls) + 2 distinct Data API calls.
    });
});
