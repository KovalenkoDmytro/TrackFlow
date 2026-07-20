<?php

declare(strict_types=1);

use App\Models\OauthCredential;
use App\Models\ShopGa4Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['cache.stores.redis' => ['driver' => 'array']]);
});

describe('GET /api/ga4/report', function (): void {
    it('returns 401 or 302 for unauthenticated requests', function (): void {
        $response = $this->getJson('/api/ga4/report?start_date=2026-07-01&end_date=2026-07-07');

        expect($response->status())->toBeIn([401, 302]);
    });

    it('returns 422 when start_date or end_date are missing', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->getJson('/api/ga4/report');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_date', 'end_date']);
    });

    it('returns 422 when end_date is before start_date', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->getJson('/api/ga4/report?start_date=2026-07-10&end_date=2026-07-01');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_date']);
    });

    it('returns 404 with a clear message when the shop has no active GA4 property configured', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->getJson('/api/ga4/report?start_date=2026-07-01&end_date=2026-07-07');

        $response->assertStatus(404);
        $response->assertJsonStructure(['message']);
    });

    it('returns 424 when the shop has a property configured but no Google operator account is connected', function (): void {
        $shop = User::factory()->create();
        ShopGa4Setting::query()->create(['user_id' => $shop->getKey(), 'property_id' => '123', 'active' => true]);

        $response = $this->actingAs($shop)->getJson('/api/ga4/report?start_date=2026-07-01&end_date=2026-07-07');

        $response->assertStatus(424);
        $response->assertJsonStructure(['message']);
    });

    it('returns the report for the shop\'s configured property on the happy path', function (): void {
        $shop = User::factory()->create();
        ShopGa4Setting::query()->create(['user_id' => $shop->getKey(), 'property_id' => '123456', 'active' => true]);
        OauthCredential::query()->create(['provider' => 'google', 'refresh_token' => 'shared-refresh-token']);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 3600], 200),
            'analyticsdata.googleapis.com/*' => Http::response(['rows' => [['metricValues' => [['value' => '10']]]]], 200),
        ]);

        $response = $this->actingAs($shop)->getJson('/api/ga4/report?start_date=2026-07-01&end_date=2026-07-07');

        $response->assertOk();
        $response->assertJsonPath('report.rows.0.metricValues.0.value', '10');

        Http::assertSent(fn ($request) => $request->url() === 'https://analyticsdata.googleapis.com/v1beta/properties/123456:runReport');
    });

    it('returns 502 when the GA4 Data API keeps failing after the automatic 401 retry', function (): void {
        $shop = User::factory()->create();
        ShopGa4Setting::query()->create(['user_id' => $shop->getKey(), 'property_id' => '123456', 'active' => true]);
        OauthCredential::query()->create(['provider' => 'google', 'refresh_token' => 'shared-refresh-token']);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 3600], 200),
            'analyticsdata.googleapis.com/*' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        $response = $this->actingAs($shop)->getJson('/api/ga4/report?start_date=2026-07-01&end_date=2026-07-07');

        $response->assertStatus(502);
        $response->assertJsonStructure(['message']);
    });

    it('serves a repeated identical request from the 60s report cache instead of re-hitting the Data API', function (): void {
        $shop = User::factory()->create();
        ShopGa4Setting::query()->create(['user_id' => $shop->getKey(), 'property_id' => '123456', 'active' => true]);
        OauthCredential::query()->create(['provider' => 'google', 'refresh_token' => 'shared-refresh-token']);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 3600], 200),
            'analyticsdata.googleapis.com/*' => Http::response(['rows' => []], 200),
        ]);

        $this->actingAs($shop)->getJson('/api/ga4/report?start_date=2026-07-01&end_date=2026-07-07')->assertOk();
        $this->actingAs($shop)->getJson('/api/ga4/report?start_date=2026-07-01&end_date=2026-07-07')->assertOk();

        // One token exchange (the access token is itself cached) + one Data API
        // call (the report result is cached) across both requests.
        Http::assertSentCount(2);
    });
});
