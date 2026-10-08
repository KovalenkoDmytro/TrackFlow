<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function reclassifySetup(int $syncDays = 7): PlatformIntegration
{
    $integration = PlatformIntegration::query()->create([
        'user_id' => User::factory()->create()->getKey(), 'platform' => Platform::GoogleAds, 'active' => true,
        'credentials' => json_encode(['customer_id' => '123-456-7890']),
        'last_error' => 'partial_failure: INVALID_CUSTOMER_FOR_CLICK', 'last_error_at' => now(),
    ]);
    foreach (range(1, $syncDays) as $d) {
        DB::table('google_ads_click_syncs')->insert([
            'platform_integration_id' => $integration->getKey(), 'customer_id' => '1234567890',
            'click_date' => now()->subDays($d)->toDateString(), 'checked_at' => now(),
        ]);
    }

    return $integration;
}

function reclassifyRow(PlatformIntegration $integration, string $gclid, ?string $body, string $status = 'partial_failure'): PlatformDelivery
{
    $event = TrackingEvent::factory()->create([
        'user_id' => $integration->user_id, 'gclid' => $gclid, 'gclid_hash' => hash('sha256', $gclid),
    ]);

    return PlatformDelivery::query()->create([
        'tracking_event_id' => $event->getKey(), 'platform_integration_id' => $integration->getKey(),
        'platform' => 'google_ads', 'status' => $status, 'attempts' => 1, 'response_body' => $body,
    ]);
}

const RECLASSIFY_BODY = '{"codes":["INVALID_CUSTOMER_FOR_CLICK"],"message":"m"}';

it('only reports counts in dry-run mode', function (): void {
    $integration = reclassifySetup();
    $row = reclassifyRow($integration, 'foreign', RECLASSIFY_BODY);

    $this->artisan('google-ads:reclassify-other-account', ['--dry-run' => true])
        ->expectsOutputToContain('Would reclassify')
        ->assertSuccessful();

    expect($row->fresh()->status)->toBe('partial_failure')
        ->and($integration->fresh()->last_error)->toBe('partial_failure: INVALID_CUSTOMER_FOR_CLICK');
});

it('reclassifies provable rows reversibly, leaves the rest untouched and resets last_error', function (): void {
    $integration = reclassifySetup();
    $foreign = reclassifyRow($integration, 'foreign', RECLASSIFY_BODY);
    $mine = reclassifyRow($integration, 'mine', RECLASSIFY_BODY);
    DB::table('google_ads_clicks')->insert([
        'platform_integration_id' => $integration->getKey(), 'customer_id' => '1234567890',
        'gclid_hash' => hash('sha256', 'mine'), 'click_date' => now()->toDateString(),
        'day_start_utc' => now()->startOfDay(), 'checked_at' => now(),
    ]);
    $noReason = reclassifyRow($integration, 'noreason', null);
    $otherCode = reclassifyRow($integration, 'othercode', '{"codes":["EXPIRED_EVENT"],"message":"m"}');
    $delivered = reclassifyRow($integration, 'delivered', null, 'delivered');

    $this->artisan('google-ads:reclassify-other-account', ['integration' => $integration->getKey()])->assertSuccessful();

    $body = json_decode((string) $foreign->fresh()->response_body, true);
    expect($foreign->fresh()->status)->toBe('other_account')
        ->and($body)->toMatchArray(['codes' => ['INVALID_CUSTOMER_FOR_CLICK'], 'previous_status' => 'partial_failure', 'customer_id' => '1234567890'])
        ->and($mine->fresh()->status)->toBe('partial_failure')
        ->and($noReason->fresh()->status)->toBe('partial_failure')
        ->and($otherCode->fresh()->status)->toBe('partial_failure')
        ->and($delivered->fresh()->status)->toBe('delivered')
        // Other partial failures remain, so the integration error must stay.
        ->and($integration->fresh()->last_error)->toBe('partial_failure: INVALID_CUSTOMER_FOR_CLICK');

    // Idempotent: a second run changes nothing.
    $before = $foreign->fresh()->response_body;
    $this->artisan('google-ads:reclassify-other-account', ['integration' => $integration->getKey()])->assertSuccessful();
    expect($foreign->fresh()->response_body)->toBe($before);
});

it('ignores expired rows even when they carry the other-account code and keeps partial_failure reclassification intact', function (): void {
    $integration = reclassifySetup();
    $expired = reclassifyRow($integration, 'expired-foreign', RECLASSIFY_BODY, 'expired');
    $foreign = reclassifyRow($integration, 'foreign', RECLASSIFY_BODY);
    $expiredBefore = $expired->fresh()->response_body;

    $this->artisan('google-ads:reclassify-other-account', ['integration' => $integration->getKey()])->assertSuccessful();

    expect($expired->fresh()->status)->toBe('expired')
        ->and($expired->fresh()->response_body)->toBe($expiredBefore)
        ->and($foreign->fresh()->status)->toBe('other_account')
        ->and(json_decode((string) $foreign->fresh()->response_body, true))->toMatchArray(['previous_status' => 'partial_failure']);
});

it('does not count expired rows as remaining failures when resetting last_error', function (): void {
    $integration = reclassifySetup();
    reclassifyRow($integration, 'old', '{"codes":["EXPIRED_EVENT"],"message":"m"}', 'expired');
    reclassifyRow($integration, 'foreign', RECLASSIFY_BODY);

    $this->artisan('google-ads:reclassify-other-account', ['integration' => $integration->getKey()])->assertSuccessful();

    expect($integration->fresh()->last_error)->toBeNull();
});

it('resets last_error when no other failure remains', function (): void {
    $integration = reclassifySetup();
    reclassifyRow($integration, 'foreign', RECLASSIFY_BODY);

    $this->artisan('google-ads:reclassify-other-account', ['integration' => $integration->getKey()])->assertSuccessful();

    expect($integration->fresh()->last_error)->toBeNull()->and($integration->fresh()->last_error_at)->toBeNull();
});

it('keeps an unrelated last_error', function (): void {
    $integration = reclassifySetup();
    $integration->update(['last_error' => 'partial_failure: INVALID_CONVERSION_ACTION']);
    reclassifyRow($integration, 'foreign', RECLASSIFY_BODY);

    $this->artisan('google-ads:reclassify-other-account', ['integration' => $integration->getKey()])->assertSuccessful();

    expect($integration->fresh()->last_error)->toBe('partial_failure: INVALID_CONVERSION_ACTION');
});

it('does nothing without enough click sync coverage', function (): void {
    $integration = reclassifySetup(syncDays: 6);
    $row = reclassifyRow($integration, 'foreign', RECLASSIFY_BODY);

    $this->artisan('google-ads:reclassify-other-account', ['integration' => $integration->getKey()])
        ->expectsOutputToContain('not enough synced')
        ->assertSuccessful();

    expect($row->fresh()->status)->toBe('partial_failure');
});

it('fails for an unknown integration', function (): void {
    $this->artisan('google-ads:reclassify-other-account', ['integration' => 9999])->assertFailed();
});
