<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Mail\HealthAlertMail;
use App\Models\ConversionActionMapping;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function healthIntegration(Platform $platform = Platform::Meta, bool $withMapping = true): PlatformIntegration
{
    $integration = PlatformIntegration::query()->create([
        'user_id' => User::factory()->create()->getKey(),
        'platform' => $platform,
        'active' => true,
        'credentials' => json_encode(['x' => 'y']),
    ]);
    if ($withMapping) {
        ConversionActionMapping::query()->create([
            'platform_integration_id' => $integration->getKey(),
            'event' => 'purchase',
            'external_action_id' => 'abc',
            'active' => true,
        ]);
    }

    return $integration;
}

beforeEach(function (): void {
    Cache::flush();
    Mail::fake();
    config()->set('alerts.email', 'example@example.com');
    config()->set('alerts.ping_url', null);
});

afterEach(fn () => Carbon::setTestNow());

it('passes and sends nothing when healthy', function (): void {
    healthIntegration();

    $this->artisan('health:check')->assertSuccessful();

    Mail::assertNothingSent();
});

it('flags a stale or missing google ads click sync', function (): void {
    $integration = healthIntegration(Platform::GoogleAds);

    $this->artisan('health:check --no-mail')->assertFailed();

    DB::table('google_ads_click_syncs')->insert([
        'platform_integration_id' => $integration->getKey(), 'customer_id' => '1234567890',
        'click_date' => now()->toDateString(), 'checked_at' => now()->subHour(),
    ]);
    $this->artisan('health:check --no-mail')->assertSuccessful();

    DB::table('google_ads_click_syncs')->update(['checked_at' => now()->subHours(4)]);
    $this->artisan('health:check --no-mail')->assertFailed();
});

it('flags recent failed queue jobs with class names but not payloads', function (): void {
    DB::table('failed_jobs')->insert([
        'uuid' => 'a', 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\Boom', 'secret' => 'SHOULD-NOT-LEAK']),
        'exception' => 'x', 'failed_at' => now()->subMinutes(10),
    ]);

    $this->artisan('health:check')->assertFailed();

    Mail::assertSent(HealthAlertMail::class, fn (HealthAlertMail $m) => str_contains($m->problems[0], 'App\\Jobs\\Boom')
        && ! str_contains(implode('', $m->problems), 'SHOULD-NOT-LEAK'));

    DB::table('failed_jobs')->update(['failed_at' => now()->subHours(2)]);
    $this->artisan('health:check --no-mail')->assertSuccessful();
});

it('flags delivery failures over the threshold only', function (): void {
    $integration = healthIntegration();
    $event = TrackingEvent::factory()->create(['user_id' => $integration->user_id]);
    $make = fn (string $status, int $n) => collect(range(1, $n))->each(fn () => PlatformDelivery::query()->create([
        'tracking_event_id' => $event->getKey(), 'platform_integration_id' => $integration->getKey(),
        'platform' => 'meta', 'status' => $status,
    ]));

    $make('failed', 4);
    $this->artisan('health:check --no-mail')->assertSuccessful(); // still retrying (attempts < tries)

    PlatformDelivery::query()->update(['attempts' => 3]);
    $this->artisan('health:check --no-mail')->assertSuccessful(); // 4 exhausted, below count

    $make('failed', 1);
    PlatformDelivery::query()->update(['attempts' => 3]);
    $this->artisan('health:check --no-mail')->assertFailed(); // 5 of 5

    $make('delivered', 10);
    $this->artisan('health:check --no-mail')->assertSuccessful(); // 5 of 15 < 50%
});

it('treats old failed rows as exhausted even with few recorded attempts', function (): void {
    $integration = healthIntegration();
    $event = TrackingEvent::factory()->create(['user_id' => $integration->user_id]);
    collect(range(1, 5))->each(fn () => PlatformDelivery::query()->create([
        'tracking_event_id' => $event->getKey(), 'platform_integration_id' => $integration->getKey(),
        'platform' => 'meta', 'status' => 'failed', 'attempts' => 1,
    ]));
    PlatformDelivery::query()->update(['updated_at' => now()->subMinutes(30)]);

    $this->artisan('health:check --no-mail')->assertFailed();
});

it('ignores partial_failure deliveries', function (): void {
    $integration = healthIntegration();
    $event = TrackingEvent::factory()->create(['user_id' => $integration->user_id]);
    collect(range(1, 20))->each(fn () => PlatformDelivery::query()->create([
        'tracking_event_id' => $event->getKey(), 'platform_integration_id' => $integration->getKey(),
        'platform' => 'meta', 'status' => 'partial_failure', 'attempts' => 1,
    ]));
    config()->set('alerts.dead_integration_min_deliveries', 100);

    $this->artisan('health:check --no-mail')->assertSuccessful();
});

it('flags an active integration with many deliveries and none delivered', function (): void {
    $integration = healthIntegration();
    $event = TrackingEvent::factory()->create(['user_id' => $integration->user_id]);
    $make = fn (string $status, int $n) => collect(range(1, $n))->each(fn () => PlatformDelivery::query()->create([
        'tracking_event_id' => $event->getKey(), 'platform_integration_id' => $integration->getKey(),
        'platform' => 'meta', 'status' => $status, 'attempts' => 1,
    ]));

    $make('partial_failure', 9);
    $this->artisan('health:check --no-mail')->assertSuccessful(); // below min deliveries

    $make('partial_failure', 1);
    $this->artisan('health:check --no-mail')->assertFailed(); // 10, none delivered

    $make('delivered', 1);
    $this->artisan('health:check --no-mail')->assertSuccessful();

    PlatformDelivery::query()->where('status', 'delivered')->delete();
    $integration->update(['active' => false]);
    $this->artisan('health:check --no-mail')->assertSuccessful(); // inactive is not dead
});

function deadRows(PlatformIntegration $integration, array $codes): void
{
    // Fresh click sync so only the dead-integration check can fire.
    DB::table('google_ads_click_syncs')->insertOrIgnore([
        'platform_integration_id' => $integration->getKey(), 'customer_id' => '1234567890',
        'click_date' => now()->toDateString(), 'checked_at' => now()->subHour(),
    ]);
    $event = TrackingEvent::factory()->create(['user_id' => $integration->user_id]);
    foreach ($codes as $code) {
        PlatformDelivery::query()->create([
            'tracking_event_id' => $event->getKey(), 'platform_integration_id' => $integration->getKey(),
            'platform' => 'google_ads', 'status' => 'partial_failure', 'attempts' => 1, 'response_code' => 200,
            'response_body' => $code === null ? null : json_encode(['codes' => [$code], 'message' => 'm']),
        ]);
    }
}

it('names the shop, top rejection code and hint in the dead integration alert', function (): void {
    $integration = healthIntegration(Platform::GoogleAds);
    deadRows($integration, [...array_fill(0, 8, 'INVALID_CUSTOMER_FOR_CLICK'), 'CLICK_NOT_FOUND', null]);

    $this->artisan('health:check')->assertFailed();

    Mail::assertSent(HealthAlertMail::class, function (HealthAlertMail $m) use ($integration): bool {
        $text = $m->render();

        return str_contains($m->problems[0], "google_ads integration {$integration->getKey()} ({$integration->user->name}): 10 deliveries in 24h, none delivered. Top reason: INVALID_CUSTOMER_FOR_CLICK (8) — the connected Google Ads account")
            && str_contains($m->problems[0], 'Other reasons: CLICK_NOT_FOUND (1).')
            && str_contains($text, 'INVALID_CUSTOMER_FOR_CLICK');
    });
});

it('does not email when the clicks demonstrably belong to another Google Ads account', function (): void {
    $integration = healthIntegration(Platform::GoogleAds);
    $integration->update(['credentials' => json_encode(['customer_id' => '123-456-7890'])]);
    deadRows($integration, array_fill(0, 10, 'INVALID_CUSTOMER_FOR_CLICK'));
    TrackingEvent::factory()->create(['user_id' => $integration->user_id, 'gclid' => 'abc', 'gclid_hash' => hash('sha256', 'abc')]);
    $problem = fn () => collect(Mail::sent(HealthAlertMail::class))->last()?->problems[0];

    // One synced day is not enough evidence, so the normal alert still fires.
    $this->artisan('health:check')->assertFailed();
    expect($problem())->toContain('INVALID_CUSTOMER_FOR_CLICK')->not->toContain('found none of');

    // Enough synced days of this account and no match: the app informs the merchant, no email.
    Cache::flush();
    Mail::fake();
    collect(range(1, 7))->each(fn (int $d) => DB::table('google_ads_click_syncs')->insert([
        'platform_integration_id' => $integration->getKey(), 'customer_id' => '1234567890',
        'click_date' => now()->subDays($d)->toDateString(), 'checked_at' => now()->subHour(),
    ]));
    $this->artisan('health:check')->assertSuccessful();
    Mail::assertNothingSent();

    // Another real problem on the same integration still alerts and carries the evidence.
    deadRows($integration, ['INVALID_CONVERSION_ACTION']);
    $this->artisan('health:check')->assertFailed();
    expect($problem())->toContain('found none of 1 captured click IDs')->toContain('INVALID_CONVERSION_ACTION');

    // A single matching click means the account does own some of the traffic: normal alert again.
    Cache::flush();
    Mail::fake();
    PlatformDelivery::query()->where('response_body', 'like', '%INVALID_CONVERSION_ACTION%')->delete();
    DB::table('google_ads_clicks')->insert([
        'platform_integration_id' => $integration->getKey(), 'customer_id' => '1234567890',
        'gclid_hash' => hash('sha256', 'abc'), 'click_date' => now()->toDateString(),
        'day_start_utc' => now()->startOfDay(), 'checked_at' => now(),
    ]);
    $this->artisan('health:check')->assertFailed();
    expect($problem())->toContain('INVALID_CUSTOMER_FOR_CLICK')->not->toContain('found none of');
});

it('does not alert when every undelivered row is a normal data condition', function (): void {
    $integration = healthIntegration(Platform::GoogleAds);
    deadRows($integration, [...array_fill(0, 9, 'EXPIRED_EVENT'), 'TOO_RECENT_EVENT']);
    $this->artisan('health:check --no-mail')->assertSuccessful();

    deadRows($integration, [null]); // a row without a known reason means we cannot call it benign
    $this->artisan('health:check --no-mail')->assertFailed();
});

it('flags active integrations without active mappings', function (): void {
    healthIntegration(Platform::Meta, withMapping: false);

    $this->artisan('health:check')->assertFailed();

    Mail::assertSent(HealthAlertMail::class, fn (HealthAlertMail $m) => str_contains($m->problems[0], 'no active conversion action mappings'));
});

it('flags a stuck queue backlog', function (): void {
    DB::table('jobs')->insert([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
        'available_at' => now()->subMinutes(30)->timestamp, 'created_at' => now()->subMinutes(30)->timestamp,
    ]);
    $this->artisan('health:check --no-mail')->assertFailed();

    DB::table('jobs')->update(['created_at' => now()->subMinutes(2)->timestamp]);
    $this->artisan('health:check --no-mail')->assertSuccessful();
});

it('sends one email and throttles the repeat run', function (): void {
    healthIntegration(withMapping: false);

    $this->artisan('health:check')->assertFailed();
    $this->artisan('health:check')->assertFailed();
    Mail::assertSent(HealthAlertMail::class, 1);

    Carbon::setTestNow(now()->addHours(7));
    $this->artisan('health:check')->assertFailed();
    Mail::assertSent(HealthAlertMail::class, 2);
});

it('sends a single recovered email when problems clear', function (): void {
    $integration = healthIntegration(withMapping: false);
    $this->artisan('health:check')->assertFailed();

    ConversionActionMapping::query()->create([
        'platform_integration_id' => $integration->getKey(), 'event' => 'purchase',
        'external_action_id' => 'abc', 'active' => true,
    ]);
    foreach (range(1, 4) as $_) {
        $this->artisan('health:check')->assertSuccessful();
    }

    Mail::assertSent(HealthAlertMail::class, fn (HealthAlertMail $m) => $m->recovered);
    Mail::assertSent(HealthAlertMail::class, 2);
});

it('requires consecutive clean runs before the recovered email and resets on relapse', function (): void {
    $integration = healthIntegration(withMapping: false);
    $this->artisan('health:check')->assertFailed();
    Mail::assertSent(HealthAlertMail::class, 1);

    $mapping = fn () => ConversionActionMapping::query()->create([
        'platform_integration_id' => $integration->getKey(), 'event' => 'purchase',
        'external_action_id' => 'abc', 'active' => true,
    ]);
    $created = $mapping();

    $this->artisan('health:check')->assertSuccessful();
    $this->artisan('health:check')->assertSuccessful(); // 2 clean runs
    $created->delete();
    $this->artisan('health:check')->assertFailed(); // relapse resets the counter
    $created = $mapping();
    $this->artisan('health:check')->assertSuccessful();
    $this->artisan('health:check')->assertSuccessful();

    expect(Mail::sent(HealthAlertMail::class, fn (HealthAlertMail $m) => $m->recovered))->toHaveCount(0);

    $this->artisan('health:check')->assertSuccessful(); // 3rd consecutive clean run
    expect(Mail::sent(HealthAlertMail::class, fn (HealthAlertMail $m) => $m->recovered))->toHaveCount(1);
});

it('does not email when ALERT_EMAIL is empty but still fails', function (): void {
    config()->set('alerts.email', '');
    healthIntegration(withMapping: false);

    $this->artisan('health:check')->assertFailed();

    Mail::assertNothingSent();
});

it('does not email with --no-mail', function (): void {
    healthIntegration(withMapping: false);

    $this->artisan('health:check --no-mail')->assertFailed();

    Mail::assertNothingSent();
});

it('pings the monitor url only after a healthy run', function (): void {
    Http::fake();
    config()->set('alerts.ping_url', 'https://ping.example.com/abc');
    $integration = healthIntegration();

    $this->artisan('health:check --no-mail')->assertSuccessful();
    Http::assertSentCount(1);

    $integration->conversionActionMappings()->delete();
    $this->artisan('health:check --no-mail')->assertFailed();
    Http::assertSentCount(1);
});
