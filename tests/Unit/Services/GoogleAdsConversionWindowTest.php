<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\PlatformIntegration;
use App\Models\User;
use App\Services\GoogleAdsConversionWindow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

mutates(GoogleAdsConversionWindow::class);

describe('isWithinWindow boundaries', function (): void {
    it('decides eligibility around the margin boundary', function (float $age, int $window, int $margin, bool $expected): void {
        expect(GoogleAdsConversionWindow::isWithinWindow($age, $window, $margin))->toBe($expected);
    })->with([
        'exactly window - margin (eligible, kills <= to <)' => [25.0, 30, 5, true],
        'one day past the boundary' => [26.0, 30, 5, false],
        'just under the boundary' => [24.999, 30, 5, true],
        'just over the boundary' => [25.001, 30, 5, false],
        'zero age' => [0.0, 30, 5, true],
        'zero margin boundary' => [30.0, 30, 0, true],
        'zero margin past boundary' => [30.001, 30, 0, false],
        'window smaller than margin, zero age' => [0.0, 3, 5, true],
        'window smaller than margin, any positive age' => [0.5, 3, 5, false],
        'window equal to margin, zero age' => [0.0, 5, 5, true],
        'window equal to margin, positive age' => [1.0, 5, 5, false],
        'future timestamp' => [-2.0, 30, 5, true],
    ]);
});

describe('windowDays resolver', function (): void {
    beforeEach(function (): void {
        Cache::flush();
        config()->set('tracking.google_ads.default_click_window_days', 30);
        $this->integration = PlatformIntegration::query()->create([
            'user_id' => User::factory()->create()->getKey(),
            'platform' => Platform::GoogleAds,
            'active' => true,
            'credentials' => json_encode([
                'customer_id' => '123-456-7890',
                'developer_token' => 'dev',
                'oauth' => ['client_id' => 'c', 'client_secret' => 's', 'refresh_token' => 'r'],
            ]),
        ]);
        $this->resource = 'customers/1234567890/conversionActions/7';
    });

    it('uses the API value and serves the second call from cache', function (): void {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            '*googleAds:search' => Http::response(['results' => [['conversionAction' => ['clickThroughLookbackWindowDays' => '45']]]]),
        ]);
        $window = app(GoogleAdsConversionWindow::class);

        expect($window->windowDays($this->integration, $this->resource))->toBe(45)
            ->and($window->windowDays($this->integration, $this->resource))->toBe(45);

        Http::assertSentCount(2); // one token request + one search, nothing on the second call
    });

    it('falls back to the config default without throwing on an HTTP failure', function (): void {
        config()->set('tracking.google_ads.default_click_window_days', 42);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            '*googleAds:search' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        expect(app(GoogleAdsConversionWindow::class)->windowDays($this->integration, $this->resource))->toBe(42);
    });

    it('falls back to the default on malformed or non-positive responses', function (mixed $body): void {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            '*googleAds:search' => Http::response($body),
        ]);

        expect(app(GoogleAdsConversionWindow::class)->windowDays($this->integration, $this->resource))->toBe(30);
    })->with([
        'no results key' => [['foo' => 'bar']],
        'empty results' => [['results' => []]],
        'zero days' => [['results' => [['conversionAction' => ['clickThroughLookbackWindowDays' => '0']]]]],
        'non-numeric days' => [['results' => [['conversionAction' => ['clickThroughLookbackWindowDays' => 'abc']]]]],
    ]);

    it('falls back to the default when credentials are not usable', function (): void {
        Http::fake();
        $this->integration->update(['credentials' => 'not-json']);

        expect(app(GoogleAdsConversionWindow::class)->windowDays($this->integration, $this->resource))->toBe(30);
        Http::assertNothingSent();
    });
});
