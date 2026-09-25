<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\TrackingEventType;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Services\GoogleAdsClickSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('counts only click IDs matched to the current account and keeps unverified events separate', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 25)->setTime(12, 0));
    $shop = User::factory()->create();
    $integration = PlatformIntegration::create([
        'user_id' => $shop->id, 'platform' => Platform::GoogleAds, 'active' => true,
        'credentials' => json_encode(['customer_id' => '1234567890']),
    ]);
    foreach (['matched', 'unverified', 'MATCHED', 'future', null, ''] as $id) {
        TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::ViewItem)
            ->occurredAt('2026-09-25 10:00:00')->create(['gclid' => $id, 'gclid_hash' => $id ? hash('sha256', $id) : null]);
    }
    foreach (['matched' => '2026-09-25 00:00:00', 'future' => '2026-09-26 00:00:00'] as $id => $start) {
        DB::table('google_ads_clicks')->insert([
            'platform_integration_id' => $integration->id, 'customer_id' => '1234567890',
            'gclid_hash' => hash('sha256', $id), 'click_date' => substr($start, 0, 10),
            'day_start_utc' => $start, 'checked_at' => now(),
        ]);
    }
    $url = '/api/analytics?platform=google_ads&date=2026-09-25';
    $this->withToken($this->shopifySessionToken($shop))->getJson($url)->assertOk()
        ->assertJsonPath('summary.total', 1)->assertJsonPath('summary.unverified_total', 3);
    $integration->update(['credentials' => json_encode(['customer_id' => '9999999999'])]);
    $this->withToken($this->shopifySessionToken($shop))->getJson($url)->assertOk()
        ->assertJsonPath('summary.total', 0)->assertJsonPath('summary.unverified_total', 4);
    $other = User::factory()->create();
    $this->withToken($this->shopifySessionToken($other))->getJson($url)->assertOk()->assertJsonPath('summary.total', 0);
    $this->travelBack();
});

it('treats every event as unverified for a freshly connected account with no synced clicks yet', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 25)->setTime(12, 0));
    $shop = User::factory()->create();
    // Integration was just connected — google_ads_clicks has zero rows because
    // no sync has run yet.
    PlatformIntegration::create([
        'user_id' => $shop->id, 'platform' => Platform::GoogleAds, 'active' => true,
        'credentials' => json_encode(['customer_id' => '1234567890']),
    ]);
    foreach (['gclid-one', 'gclid-two'] as $id) {
        TrackingEvent::factory()->forUser($shop)->forEvent(TrackingEventType::ViewItem)
            ->occurredAt('2026-09-25 10:00:00')->create(['gclid' => $id, 'gclid_hash' => hash('sha256', $id)]);
    }
    $url = '/api/analytics?platform=google_ads&date=2026-09-25';
    $this->withToken($this->shopifySessionToken($shop))->getJson($url)->assertOk()
        ->assertJsonPath('summary.total', 0)
        ->assertJsonPath('summary.unverified_total', 2)
        ->assertJsonPath('summary.attribution.last_checked_at', null);
    $this->travelBack();
});

it('syncs paginated click reports in account timezone and hashes historical events', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 01:00:00', 'UTC'));
    $shop = User::factory()->create();
    $integration = PlatformIntegration::create([
        'user_id' => $shop->id, 'platform' => Platform::GoogleAds, 'active' => true,
        'credentials' => json_encode(['customer_id' => '1234567890', 'oauth' => [
            'client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'token',
        ]]),
    ]);
    $event = TrackingEvent::factory()->forUser($shop)->create(['gclid' => 'CaseSensitive', 'gclid_hash' => null]);
    $fail = false;
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access']),
        'https://googleads.googleapis.com/*' => function ($request) use (&$fail) {
            if ($fail && isset($request['pageToken'])) {
                return Http::response([], 503);
            }
            if (str_contains($request['query'], 'customer.time_zone')) {
                return Http::response(['results' => [['customer' => ['timeZone' => 'America/Edmonton']]]]);
            }
            expect($request['query'])->toContain("segments.date = '2026-09-24'");
            expect($request->hasHeader('developer-token'))->toBeFalse();

            return isset($request['pageToken'])
                ? Http::response(['results' => [['clickView' => ['gclid' => 'second']]]])
                : Http::response(['results' => [['clickView' => ['gclid' => 'CaseSensitive']]], 'nextPageToken' => 'page2']);
        },
    ]);
    app(GoogleAdsClickSync::class)->sync($integration, 1);
    expect(DB::table('google_ads_clicks')->count())->toBe(2)
        ->and($event->fresh()->gclid_hash)->toBe(hash('sha256', 'CaseSensitive'))
        ->and(DB::table('google_ads_clicks')->value('day_start_utc'))->toBe('2026-09-24 06:00:00');
    // A failed report must not turn previously matched clicks into unmatched ones.
    $fail = true;
    expect(fn () => app(GoogleAdsClickSync::class)->sync($integration, 1))->toThrow(RuntimeException::class);
    expect(DB::table('google_ads_clicks')->count())->toBe(2);
    $this->travelBack();
});
