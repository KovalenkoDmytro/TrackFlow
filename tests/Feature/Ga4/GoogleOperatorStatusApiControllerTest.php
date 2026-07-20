<?php

declare(strict_types=1);

use App\Models\OauthCredential;
use App\Models\User;

describe('GET /api/operator/google-status', function (): void {
    it('returns 401 or 302 for unauthenticated requests', function (): void {
        $response = $this->getJson('/api/operator/google-status');

        expect($response->status())->toBeIn([401, 302]);
    });

    it('reports is_operator=false and connected=false for a non-operator shop when nothing is connected', function (): void {
        $shop = User::factory()->create();
        config(['services.operators' => []]);

        $response = $this->actingAs($shop)->getJson('/api/operator/google-status');

        $response->assertOk();
        $response->assertJson(['is_operator' => false, 'connected' => false, 'connected_at' => null]);
    });

    it('reports is_operator=true for a shop whose email is in the operators allowlist', function (): void {
        $shop = User::factory()->create(['email' => 'operator@example.com']);
        config(['services.operators' => ['operator@example.com']]);

        $response = $this->actingAs($shop)->getJson('/api/operator/google-status');

        $response->assertOk();
        $response->assertJsonPath('is_operator', true);
    });

    it('reports connected=true with a connected_at timestamp when an active credential exists', function (): void {
        $shop = User::factory()->create();
        OauthCredential::query()->create([
            'provider' => 'google',
            'refresh_token' => 'a-real-refresh-token',
            'connected_at' => now(),
        ]);

        $response = $this->actingAs($shop)->getJson('/api/operator/google-status');

        $response->assertOk();
        $response->assertJsonPath('connected', true);
        expect($response->json('connected_at'))->not->toBeNull();
    });

    it('reports connected=false when the credential is revoked', function (): void {
        $shop = User::factory()->create();
        OauthCredential::query()->create([
            'provider' => 'google',
            'refresh_token' => 'a-real-refresh-token',
            'revoked_at' => now(),
        ]);

        $response = $this->actingAs($shop)->getJson('/api/operator/google-status');

        $response->assertOk();
        $response->assertJsonPath('connected', false);
    });

    it('reports connected=false when the refresh_token has been cleared', function (): void {
        $shop = User::factory()->create();
        OauthCredential::query()->create([
            'provider' => 'google',
            'refresh_token' => null,
        ]);

        $response = $this->actingAs($shop)->getJson('/api/operator/google-status');

        $response->assertOk();
        $response->assertJsonPath('connected', false);
    });

    it('never exposes the raw refresh_token value in the response', function (): void {
        $shop = User::factory()->create();
        OauthCredential::query()->create([
            'provider' => 'google',
            'refresh_token' => 'super-secret-value-12345',
        ]);

        $response = $this->actingAs($shop)->getJson('/api/operator/google-status');

        expect($response->getContent())->not->toContain('super-secret-value-12345');
        expect($response->getContent())->not->toContain('refresh_token');
    });
});
