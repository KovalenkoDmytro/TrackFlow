<?php

declare(strict_types=1);

use App\Models\OauthCredential;
use App\Models\ShopGa4Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

if (! function_exists('fakeGa4PropertyVerificationSucceeds')) {
    /**
     * Http::fake() matches stubs in registration order (first match wins), so
     * a wildcard "success" stub registered in a beforeEach would always win
     * over a more specific per-test failure stub registered afterwards. Each
     * test therefore fakes its own Admin API response instead of sharing one
     * from a beforeEach.
     */
    function fakeGa4PropertyVerificationSucceeds(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 3600], 200),
            'analyticsadmin.googleapis.com/*' => Http::response([
                'displayName' => 'My Store',
                'timeZone' => 'America/Toronto',
                'currencyCode' => 'CAD',
            ], 200),
        ]);
    }
}

beforeEach(function (): void {
    config(['cache.stores.redis' => ['driver' => 'array']]);

    OauthCredential::query()->create(['provider' => 'google', 'refresh_token' => 'shared-refresh-token']);
});

describe('GET /api/settings/ga4-property', function (): void {
    it('returns 401 or 302 for unauthenticated requests', function (): void {
        $response = $this->getJson('/api/settings/ga4-property');

        expect($response->status())->toBeIn([401, 302]);
    });

    it('returns setting=null when the shop has no GA4 property configured', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->getJson('/api/settings/ga4-property');

        $response->assertOk();
        $response->assertExactJson(['setting' => null]);
    });

    it('returns the shop\'s configured property', function (): void {
        $shop = User::factory()->create();
        ShopGa4Setting::query()->create([
            'user_id' => $shop->getKey(),
            'property_id' => '123456',
            'property_display_name' => 'My Store',
            'property_timezone' => 'America/Toronto',
            'property_currency' => 'CAD',
            'active' => true,
        ]);

        $response = $this->actingAs($shop)->getJson('/api/settings/ga4-property');

        $response->assertOk();
        $response->assertJsonPath('setting.property_id', '123456');
        $response->assertJsonPath('setting.property_display_name', 'My Store');
        $response->assertJsonPath('setting.active', true);
    });

    it('never returns another shop\'s property', function (): void {
        $shopA = User::factory()->create();
        $shopB = User::factory()->create();
        ShopGa4Setting::query()->create(['user_id' => $shopA->getKey(), 'property_id' => '111', 'active' => true]);

        $response = $this->actingAs($shopB)->getJson('/api/settings/ga4-property');

        $response->assertOk();
        $response->assertExactJson(['setting' => null]);
    });
});

describe('PUT /api/settings/ga4-property', function (): void {
    it('returns 401 or 302 for unauthenticated requests', function (): void {
        $response = $this->putJson('/api/settings/ga4-property', ['property_id' => '123']);

        expect($response->status())->toBeIn([401, 302]);
    });

    it('creates the setting with active=true on first save', function (): void {
        $shop = User::factory()->create();
        fakeGa4PropertyVerificationSucceeds();

        $response = $this->actingAs($shop)->putJson('/api/settings/ga4-property', ['property_id' => '123456789']);

        $response->assertOk();
        $response->assertJsonPath('setting.property_id', '123456789');
        $response->assertJsonPath('setting.active', true);

        $setting = ShopGa4Setting::query()->where('user_id', $shop->getKey())->first();
        expect($setting->property_id)->toBe('123456789');
        expect($setting->property_display_name)->toBe('My Store');
        expect($setting->property_timezone)->toBe('America/Toronto');
        expect($setting->property_currency)->toBe('CAD');
        expect($setting->last_verified_at)->not->toBeNull();

        Http::assertSent(fn ($request) => $request->url() === 'https://analyticsadmin.googleapis.com/v1beta/properties/123456789');
    });

    it('returns 422 and does not save when the shared operator account cannot access the property', function (): void {
        $shop = User::factory()->create();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 3600], 200),
            'analyticsadmin.googleapis.com/*' => Http::response(['error' => ['message' => 'The caller does not have permission']], 403),
        ]);

        $response = $this->actingAs($shop)->putJson('/api/settings/ga4-property', ['property_id' => '999999999']);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message']);

        // The raw Google error must not leak to the client.
        expect($response->json('message'))->not->toContain('permission');

        expect(ShopGa4Setting::query()->where('user_id', $shop->getKey())->exists())->toBeFalse();
    });

    it('returns 422 and does not save when no Google operator account is connected', function (): void {
        $shop = User::factory()->create();
        OauthCredential::query()->where('provider', 'google')->delete();

        $response = $this->actingAs($shop)->putJson('/api/settings/ga4-property', ['property_id' => '123456789']);

        $response->assertStatus(422);
        expect(ShopGa4Setting::query()->where('user_id', $shop->getKey())->exists())->toBeFalse();
    });

    it('updates the existing setting for the shop instead of creating a duplicate row', function (): void {
        $shop = User::factory()->create();
        ShopGa4Setting::query()->create(['user_id' => $shop->getKey(), 'property_id' => '111', 'active' => false]);
        fakeGa4PropertyVerificationSucceeds();

        $response = $this->actingAs($shop)->putJson('/api/settings/ga4-property', ['property_id' => '222']);

        $response->assertOk();
        expect(ShopGa4Setting::query()->where('user_id', $shop->getKey())->count())->toBe(1);
        expect(ShopGa4Setting::query()->where('user_id', $shop->getKey())->first())
            ->property_id->toBe('222')
            ->active->toBeTrue();
    });

    it('returns 422 when property_id is not numeric', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->putJson('/api/settings/ga4-property', ['property_id' => 'not-a-number']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['property_id']);
    });

    it('returns 422 when property_id is missing', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->putJson('/api/settings/ga4-property', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['property_id']);
    });

    it('returns 424 without leaking exception details when the token refresh lock times out', function (): void {
        $shop = User::factory()->create();

        Sleep::fake();

        // Simulate lock contention: hold the same distributed lock
        // Ga4TokenProvider::getAccessToken() blocks on, so its ->block(5, ...)
        // exhausts its wait and throws LockTimeoutException.
        Cache::store('redis')->lock('ga4:token-refresh:google', 10)->get();

        $response = $this->actingAs($shop)->putJson('/api/settings/ga4-property', ['property_id' => '123456789']);

        $response->assertStatus(424);
        $response->assertJsonStructure(['message']);

        // The raw exception must not leak to the client.
        expect($response->json('message'))->not->toContain('LockTimeoutException');

        expect(ShopGa4Setting::query()->where('user_id', $shop->getKey())->exists())->toBeFalse();
    });
});
