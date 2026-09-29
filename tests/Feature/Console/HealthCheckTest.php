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
    $this->artisan('health:check --no-mail')->assertSuccessful(); // below count

    $make('partial_failure', 1);
    $this->artisan('health:check --no-mail')->assertFailed(); // 5 of 5

    $make('delivered', 10);
    $this->artisan('health:check --no-mail')->assertSuccessful(); // 5 of 15 < 50%
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
    $this->artisan('health:check')->assertSuccessful();
    $this->artisan('health:check')->assertSuccessful();

    Mail::assertSent(HealthAlertMail::class, fn (HealthAlertMail $m) => $m->recovered);
    Mail::assertSent(HealthAlertMail::class, 2);
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
