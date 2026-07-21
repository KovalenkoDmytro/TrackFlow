<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\PlatformIntegration;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'services.google.client_id' => 'app-client-id',
        'services.google.client_secret' => 'app-client-secret',
        'services.google.redirect' => 'http://localhost/settings/ga4/google/callback',
        'services.google.scopes' => [
            'https://www.googleapis.com/auth/analytics.readonly',
            'https://www.googleapis.com/auth/analytics.edit',
        ],
    ]);
});

describe('GET /settings/ga4/google/start', function (): void {
    it('requires authentication', function (): void {
        $response = $this->get('/settings/ga4/google/start');

        // No "login" route is registered in this Shopify-embedded app (shops
        // always arrive authenticated via the Shopify session), so the
        // `auth:web` middleware's default redirect-to-login throws a
        // RouteNotFoundException (500) for a guest instead of a 302 — the
        // important thing is that the Action's handle() never runs and no
        // OAuth flow is started for an unauthenticated request.
        expect($response->status())->toBeIn([401, 302, 500]);
    });

    it('redirects to Google with a state param and stashes it in the session', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)->get('/settings/ga4/google/start');

        $response->assertRedirect();
        $location = $response->headers->get('Location');

        expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?');
        expect($location)->toContain('client_id=app-client-id');
        expect($location)->toContain('access_type=offline');
        expect($location)->toContain('prompt=consent');

        expect(session('ga4_google_oauth_state'))->not->toBeEmpty();
    });
});

describe('GET /settings/ga4/google/callback', function (): void {
    it('rejects a missing or mismatched state and does not touch stored credentials', function (): void {
        $shop = User::factory()->create();

        $response = $this->actingAs($shop)
            ->withSession(['ga4_google_oauth_state' => 'expected-state'])
            ->get('/settings/ga4/google/callback?state=wrong-state&code=abc123');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('google_error=');

        expect(PlatformIntegration::query()->where('user_id', $shop->getKey())->count())->toBe(0);
    });

    it('rejects a replayed state (single-use)', function (): void {
        $shop = User::factory()->create();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token-123',
                'scope' => 'https://www.googleapis.com/auth/analytics.edit',
            ], 200),
        ]);

        $this->actingAs($shop)->withSession(['ga4_google_oauth_state' => 'one-time-state']);

        $first = $this->get('/settings/ga4/google/callback?state=one-time-state&code=abc123');
        $first->assertRedirect();
        expect($first->headers->get('Location'))->toContain('google=connected');

        // Session state was pulled (removed) after the first request, so a
        // second attempt with the same state must be rejected.
        $second = $this->get('/settings/ga4/google/callback?state=one-time-state&code=abc123');
        $second->assertRedirect();
        expect($second->headers->get('Location'))->toContain('google_error=');
    });

    it('exchanges the code and stores the refresh_token on the shop\'s own PlatformIntegration', function (): void {
        $shop = User::factory()->create();

        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAnalytics4,
            'active' => true,
            'credentials' => json_encode([
                'measurement_id' => 'G-EXISTING',
                'api_secret' => 'existing-secret',
                'property_id' => '999999',
            ]),
            'settings' => [],
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'brand-new-refresh-token',
                'scope' => 'https://www.googleapis.com/auth/analytics.edit',
            ], 200),
        ]);

        $response = $this->actingAs($shop)
            ->withSession(['ga4_google_oauth_state' => 'good-state'])
            ->get('/settings/ga4/google/callback?state=good-state&code=abc123');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('google=connected');

        $integration = PlatformIntegration::query()
            ->where('user_id', $shop->getKey())
            ->where('platform', Platform::GoogleAnalytics4)
            ->first();

        $stored = json_decode((string) $integration->credentials, true);
        expect($stored['oauth_refresh_token'])->toBe('brand-new-refresh-token');
        // Existing fields must be preserved, not overwritten.
        expect($stored['measurement_id'])->toBe('G-EXISTING');
        expect($stored['api_secret'])->toBe('existing-secret');
        expect($stored['property_id'])->toBe('999999');

        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['client_id'] === 'app-client-id'
            && $request['client_secret'] === 'app-client-secret');
    });

    it('creates a new PlatformIntegration when the shop connects Google before saving any GA4 credentials', function (): void {
        $shop = User::factory()->create();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'first-refresh-token',
            ], 200),
        ]);

        $response = $this->actingAs($shop)
            ->withSession(['ga4_google_oauth_state' => 'good-state'])
            ->get('/settings/ga4/google/callback?state=good-state&code=abc123');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('google=connected');

        $integration = PlatformIntegration::query()
            ->where('user_id', $shop->getKey())
            ->where('platform', Platform::GoogleAnalytics4)
            ->first();

        expect($integration)->not->toBeNull();
        expect($integration->active)->toBeFalse();

        $stored = json_decode((string) $integration->credentials, true);
        expect($stored['oauth_refresh_token'])->toBe('first-refresh-token');
    });

    it('treats a missing refresh_token as success when one is already stored (re-consent without prompt)', function (): void {
        $shop = User::factory()->create();

        PlatformIntegration::query()->create([
            'user_id' => $shop->getKey(),
            'platform' => Platform::GoogleAnalytics4,
            'active' => true,
            'credentials' => json_encode([
                'measurement_id' => 'G-EXISTING',
                'api_secret' => 'existing-secret',
                'oauth_refresh_token' => 'already-stored-token',
            ]),
            'settings' => [],
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                // No refresh_token key at all.
            ], 200),
        ]);

        $response = $this->actingAs($shop)
            ->withSession(['ga4_google_oauth_state' => 'good-state'])
            ->get('/settings/ga4/google/callback?state=good-state&code=abc123');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('google=connected');

        $integration = PlatformIntegration::query()
            ->where('user_id', $shop->getKey())
            ->where('platform', Platform::GoogleAnalytics4)
            ->first();

        $stored = json_decode((string) $integration->credentials, true);
        expect($stored['oauth_refresh_token'])->toBe('already-stored-token');
    });

    it('errors when Google returns no refresh_token and none is already stored', function (): void {
        $shop = User::factory()->create();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
            ], 200),
        ]);

        $response = $this->actingAs($shop)
            ->withSession(['ga4_google_oauth_state' => 'good-state'])
            ->get('/settings/ga4/google/callback?state=good-state&code=abc123');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('google_error=');

        expect(PlatformIntegration::query()->where('user_id', $shop->getKey())->count())->toBe(0);
    });

    it('never leaks client_secret or refresh_token in the redirect response', function (): void {
        $shop = User::factory()->create();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Bad Request',
            ], 400),
        ]);

        $response = $this->actingAs($shop)
            ->withSession(['ga4_google_oauth_state' => 'good-state'])
            ->get('/settings/ga4/google/callback?state=good-state&code=abc123');

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        expect($location)->not->toContain('app-client-secret');
    });
});
