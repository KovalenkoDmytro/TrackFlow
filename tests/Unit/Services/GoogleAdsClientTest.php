<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\ConversionActionMapping;
use App\Models\PlatformIntegration;
use App\Models\User;
use App\Services\GoogleAdsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('repairs mappings from existing Google Ads conversion actions without creating duplicates', function (): void {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
        'https://googleads.googleapis.com/v24/customers/1234567890/googleAds:search' => Http::response([
            'results' => [
                ['conversionAction' => [
                    'resourceName' => 'customers/1234567890/conversionActions/100',
                    'name' => 'TF - purchase',
                ]],
            ],
        ]),
        'https://googleads.googleapis.com/v24/customers/1234567890/conversionActions:mutate' => function ($request) {
            $name = $request->data()['operations'][0]['create']['name'];
            $event = substr($name, strlen('TF - '));

            return Http::response([
                'results' => [[
                    'resourceName' => "customers/1234567890/conversionActions/new-{$event}",
                ]],
            ]);
        },
    ]);

    $shop = User::factory()->create();
    $integration = PlatformIntegration::query()->create([
        'user_id' => $shop->getKey(),
        'platform' => Platform::GoogleAds,
        'active' => true,
        'credentials' => json_encode([
            'customer_id' => '123-456-7890',
            'developer_token' => 'developer-token',
            'oauth' => [
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'refresh_token' => 'refresh-token',
            ],
        ]),
    ]);

    (new GoogleAdsClient)->setupConversionActions($integration);

    expect(ConversionActionMapping::query()->where('platform_integration_id', $integration->getKey())->count())
        ->toBe(9)
        ->and(ConversionActionMapping::query()
            ->where('platform_integration_id', $integration->getKey())
            ->where('event', 'purchase')
            ->value('external_action_id'))
        ->toBe('customers/1234567890/conversionActions/100');

    Http::assertSentCount(18); // List OAuth/search + OAuth/mutate for each of 8 missing actions.
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/conversionActions:mutate')
        && $request->data()['operations'][0]['create']['name'] === 'TF - purchase');
});
