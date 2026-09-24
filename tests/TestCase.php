<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Builds a real signed HS256 Shopify session-token JWT for `$shop`, using
     * the exact header App\Actions\Shopify\ResolveShopFromSessionToken's
     * underlying vendor parser pins via regex
     * (`{"alg":"HS256","typ":"JWT"}`, base64url-encoded).
     *
     * @param  array<string, mixed>  $claimOverrides  Merged over the default
     *                                                claims — pass an explicit `null` (e.g. `['sid' => null]`) to
     *                                                simulate a claim that Shopify omitted, since the vendor parser
     *                                                treats a null claim the same as a missing one.
     */
    public function shopifySessionToken(User $shop, array $claimOverrides = []): string
    {
        // Carbon::now() (not time()) so this respects $this->travelTo() in
        // callers that freeze "now" — the vendor's own expiration check reads
        // Carbon::now(), and a real-clock timestamp here would otherwise
        // drift arbitrarily far from a frozen test clock.
        $now = Carbon::now()->getTimestamp();

        $claims = array_merge([
            'iss' => "https://{$shop->name}/admin",
            'dest' => "https://{$shop->name}",
            'aud' => (string) config('shopify-app.api_key'),
            'sub' => (string) $shop->getKey(),
            'exp' => $now + 60,
            'nbf' => $now,
            'iat' => $now,
            'jti' => Str::random(16),
            'sid' => Str::random(16),
        ], $claimOverrides);

        // Fixed HS256/JWT header — the vendor's token-format regex pins this
        // exact base64url string, so it is hardcoded rather than computed.
        $header = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9';
        $payload = $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signingInput = "{$header}.{$payload}";

        $signature = $this->base64UrlEncode(
            hash_hmac('sha256', $signingInput, (string) config('shopify-app.api_secret'), true),
        );

        return "{$signingInput}.{$signature}";
    }

    /**
     * HMAC-signs a Shopify embedded-launch query array the same way Shopify
     * itself does: drop any existing `hmac`, sort the remaining params, and
     * hash the urldecoded query string.
     *
     * @param  array<string, string>  $params
     * @return array<string, string>
     */
    public function signShopifyLaunchQuery(array $params): array
    {
        $signable = $params;
        unset($signable['hmac']);
        ksort($signable);

        $hmac = hash_hmac(
            'sha256',
            urldecode(http_build_query($signable)),
            (string) config('shopify-app.api_secret'),
        );

        return [...$params, 'hmac' => $hmac];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
