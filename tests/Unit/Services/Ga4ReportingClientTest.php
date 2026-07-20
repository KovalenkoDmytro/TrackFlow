<?php

declare(strict_types=1);

use App\Models\OauthCredential;
use App\Services\Ga4ReportingClient;
use App\Services\Ga4TokenProvider;
use Illuminate\Support\Facades\Http;

mutates(Ga4ReportingClient::class);

/**
 * Ga4ReportingClient and Ga4TokenProvider are both `final` by design, so they
 * cannot be mocked with Mockery. These tests exercise the real Ga4TokenProvider
 * behind Ga4ReportingClient and use Http::fake() as the seam instead.
 */
beforeEach(function (): void {
    config(['cache.stores.redis' => ['driver' => 'array']]);
    OauthCredential::query()->create(['provider' => 'google', 'refresh_token' => 'stored-refresh-token']);
});

describe('Ga4ReportingClient::runReport', function (): void {
    it('resolves an access token and posts the report body to the correct runReport endpoint', function (): void {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token-1', 'expires_in' => 3600], 200),
            'analyticsdata.googleapis.com/*' => Http::response(['rows' => [['metricValues' => [['value' => '42']]]]], 200),
        ]);

        $client = new Ga4ReportingClient(new Ga4TokenProvider);
        $result = $client->runReport('123456', ['dateRanges' => [['startDate' => '2026-07-01', 'endDate' => '2026-07-07']]]);

        expect($result)->toBe(['rows' => [['metricValues' => [['value' => '42']]]]]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://analyticsdata.googleapis.com/v1beta/properties/123456:runReport'
                && $request->hasHeader('Authorization', 'Bearer access-token-1')
                && $request['dateRanges'][0]['startDate'] === '2026-07-01';
        });
    });

    it('invalidates the cached token and retries once on a 401, succeeding with a freshly refreshed token', function (): void {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'stale-token', 'expires_in' => 3600], 200)
                ->push(['access_token' => 'fresh-token', 'expires_in' => 3600], 200),
            'analyticsdata.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'token expired']], 401)
                ->push(['rows' => []], 200),
        ]);

        $client = new Ga4ReportingClient(new Ga4TokenProvider);
        $result = $client->runReport('123456', []);

        expect($result)->toBe(['rows' => []]);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer fresh-token'));
        Http::assertSentCount(4); // 2 token exchanges + 2 Data API attempts.
    });

    it('surfaces a RuntimeException with the API error message when the retried request is still a 401', function (): void {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'token-a', 'expires_in' => 3600], 200)
                ->push(['access_token' => 'token-b', 'expires_in' => 3600], 200),
            'analyticsdata.googleapis.com/*' => Http::response(['error' => ['message' => 'still unauthorized']], 401),
        ]);

        $client = new Ga4ReportingClient(new Ga4TokenProvider);

        expect(fn () => $client->runReport('123456', []))
            ->toThrow(RuntimeException::class, 'GA4 Data API error [401]: still unauthorized');

        Http::assertSentCount(4); // 2 token exchanges + 2 Data API attempts (no further retry).
    });

    it('throws a RuntimeException without retrying for non-401 errors', function (): void {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600], 200),
            'analyticsdata.googleapis.com/*' => Http::response(['error' => ['message' => 'bad property id']], 400),
        ]);

        $client = new Ga4ReportingClient(new Ga4TokenProvider);

        expect(fn () => $client->runReport('123456', []))
            ->toThrow(RuntimeException::class, 'GA4 Data API error [400]: bad property id');

        Http::assertSentCount(2); // 1 token exchange + 1 Data API attempt, no retry for a non-401.
    });
});
