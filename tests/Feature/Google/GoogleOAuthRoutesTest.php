<?php

declare(strict_types=1);

use App\Models\OauthCredential;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

if (! function_exists('operatorShop')) {
    function operatorShop(string $email = 'operator@example.com'): User
    {
        $shop = User::factory()->create(['email' => $email]);
        config(['services.operators' => [$email]]);

        return $shop;
    }
}

if (! function_exists('withValidOAuthState')) {
    /**
     * Pre-seeds the session with the `google_oauth_state` value StartGoogleOAuth
     * would have stashed, and returns the callback query string fragment
     * (`state=...`) that matches it — so tests can hit the callback directly
     * without going through /start first, while still passing state validation.
     */
    function withValidOAuthState(TestCase $test, string $state = 'valid-test-state'): string
    {
        $test->withSession(['google_oauth_state' => $state]);

        return 'state='.$state;
    }
}

describe('connect-google Gate authorization', function (): void {
    /**
     * This app never registers a route named "login" (it is a
     * Shopify-embedded app with no classic login page). bootstrap/app.php
     * overrides the framework's default guest-redirect target (which would
     * otherwise call route('login') and throw a RouteNotFoundException,
     * surfacing as a 500) with a plain 401 response instead.
     */
    it('returns 401 (instead of a 500) for unauthenticated requests', function (): void {
        expect($this->get('/operator/google/start')->status())->toBe(401);
        expect($this->get('/operator/google/callback')->status())->toBe(401);
        expect($this->post('/operator/google/disconnect')->status())->toBe(401);
    });

    it('returns 403 for an authenticated shop that is not in the operators allowlist', function (): void {
        $shop = User::factory()->create();
        config(['services.operators' => []]);

        expect($this->actingAs($shop)->get('/operator/google/start')->status())->toBe(403);
        expect($this->actingAs($shop)->get('/operator/google/callback')->status())->toBe(403);
        expect($this->actingAs($shop)->post('/operator/google/disconnect')->status())->toBe(403);
    });

    it('allows an authenticated shop whose email is in the operators allowlist past the gate', function (): void {
        $shop = operatorShop();

        $response = $this->actingAs($shop)->get('/operator/google/start');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('accounts.google.com');
    });
});

describe('GET /operator/google/start', function (): void {
    it('redirects to Google\'s consent screen with offline access and forced consent', function (): void {
        $shop = operatorShop();
        config(['services.google.client_id' => 'test-client-id']);
        config(['services.google.redirect' => 'https://app.example.com/operator/google/callback']);

        $response = $this->actingAs($shop)->get('/operator/google/start');

        $response->assertRedirect();
        $location = $response->headers->get('Location');

        expect($location)->toContain('https://accounts.google.com/o/oauth2/v2/auth');
        expect($location)->toContain('access_type=offline');
        expect($location)->toContain('prompt=consent');
        expect($location)->toContain('client_id=test-client-id');
        expect($location)->toContain('state=');

        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
        expect($query['state'] ?? '')->toHaveLength(40);
    });
});

describe('GET /operator/google/callback', function (): void {
    it('upserts the shared OauthCredential with the encrypted refresh_token on a successful exchange', function (): void {
        $shop = operatorShop();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ignored-here',
                'refresh_token' => 'brand-new-refresh-token',
                'scope' => 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/analytics.edit',
                'expires_in' => 3600,
            ], 200),
        ]);

        $state = withValidOAuthState($this);
        $response = $this->actingAs($shop)->get("/operator/google/callback?code=auth-code-123&{$state}");

        $response->assertRedirect('/');
        $response->assertSessionHas('status');

        $credential = OauthCredential::query()->where('provider', 'google')->first();
        expect($credential)->not->toBeNull();
        expect($credential->refresh_token)->toBe('brand-new-refresh-token');
        expect($credential->connected_by)->toBe($shop->getKey());
        expect($credential->revoked_at)->toBeNull();
        expect($credential->scopes)->toBe([
            'https://www.googleapis.com/auth/analytics.readonly',
            'https://www.googleapis.com/auth/analytics.edit',
        ]);
    });

    it('clears a previous revocation when reconnecting', function (): void {
        $shop = operatorShop();
        OauthCredential::query()->create([
            'provider' => 'google',
            'refresh_token' => null,
            'revoked_at' => now(),
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'refresh_token' => 'reconnected-refresh-token',
                'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            ], 200),
        ]);

        $state = withValidOAuthState($this);
        $this->actingAs($shop)->get("/operator/google/callback?code=auth-code-456&{$state}");

        $credential = OauthCredential::query()->where('provider', 'google')->first();
        expect($credential->refresh_token)->toBe('reconnected-refresh-token');
        expect($credential->revoked_at)->toBeNull();
    });

    it('redirects with an error and does not touch the credential when Google returns no code', function (): void {
        $shop = operatorShop();
        $state = withValidOAuthState($this);

        $response = $this->actingAs($shop)->get("/operator/google/callback?{$state}");

        $response->assertRedirect('/');
        $response->assertSessionHas('error');
        expect(OauthCredential::query()->where('provider', 'google')->exists())->toBeFalse();
    });

    it('redirects with an error and does not persist anything when the token exchange itself fails', function (): void {
        $shop = operatorShop();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $state = withValidOAuthState($this);
        $response = $this->actingAs($shop)->get("/operator/google/callback?code=auth-code-789&{$state}");

        $response->assertRedirect('/');
        $response->assertSessionHas('error');
        expect(OauthCredential::query()->where('provider', 'google')->exists())->toBeFalse();
    });

    it('redirects with an error instead of silently succeeding when Google omits the refresh_token', function (): void {
        $shop = operatorShop();

        // The "forgot prompt=consent on a returning grant" case: Google returns
        // 200 with an access_token but no refresh_token.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'short-lived-access-token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $state = withValidOAuthState($this);
        $response = $this->actingAs($shop)->get("/operator/google/callback?code=auth-code-abc&{$state}");

        $response->assertRedirect('/');
        $response->assertSessionHas('error');
        expect(OauthCredential::query()->where('provider', 'google')->exists())->toBeFalse();
    });

    it('does not overwrite an existing valid refresh_token when a later exchange omits one', function (): void {
        $shop = operatorShop();
        OauthCredential::query()->create([
            'provider' => 'google',
            'refresh_token' => 'existing-good-refresh-token',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'short-lived'], 200),
        ]);

        $state = withValidOAuthState($this);
        $this->actingAs($shop)->get("/operator/google/callback?code=auth-code-def&{$state}");

        $credential = OauthCredential::query()->where('provider', 'google')->first();
        expect($credential->refresh_token)->toBe('existing-good-refresh-token');
    });

    it('redirects with an error and does not touch the credential when the state param is missing', function (): void {
        $shop = operatorShop();
        withValidOAuthState($this);

        $response = $this->actingAs($shop)->get('/operator/google/callback?code=auth-code-no-state');

        $response->assertRedirect('/');
        $response->assertSessionHas('error');
        expect(OauthCredential::query()->where('provider', 'google')->exists())->toBeFalse();
    });

    it('redirects with an error and does not touch the credential when the state param does not match the session', function (): void {
        $shop = operatorShop();
        withValidOAuthState($this, 'expected-state');

        $response = $this->actingAs($shop)->get('/operator/google/callback?code=auth-code-bad-state&state=attacker-supplied-state');

        $response->assertRedirect('/');
        $response->assertSessionHas('error');
        expect(OauthCredential::query()->where('provider', 'google')->exists())->toBeFalse();
    });

    it('rejects a replayed state value on a second callback request', function (): void {
        $shop = operatorShop();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['refresh_token' => 'first-token'], 200),
        ]);

        $state = withValidOAuthState($this, 'single-use-state');
        $this->actingAs($shop)->get("/operator/google/callback?code=auth-code-1&{$state}")->assertSessionHas('status');

        // The session value was consumed by the first request; replaying the
        // same state on a second callback must be rejected.
        $response = $this->actingAs($shop)->get("/operator/google/callback?code=auth-code-2&{$state}");

        $response->assertSessionHas('error');
    });
});

describe('POST /operator/google/disconnect', function (): void {
    it('revokes the credential and nulls out the refresh_token so it can never be reused', function (): void {
        $shop = operatorShop();
        OauthCredential::query()->create([
            'provider' => 'google',
            'refresh_token' => 'currently-connected-refresh-token',
        ]);

        $response = $this->actingAs($shop)->post('/operator/google/disconnect');

        $response->assertRedirect('/');
        $response->assertSessionHas('status');

        $credential = OauthCredential::query()->where('provider', 'google')->first();
        expect($credential->refresh_token)->toBeNull();
        expect($credential->revoked_at)->not->toBeNull();
    });
});
