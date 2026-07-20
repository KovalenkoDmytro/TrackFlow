<?php

declare(strict_types=1);

namespace App\Actions\Ga4;

use App\Models\User;
use App\Services\Ga4ReportingClient;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves a shop's ShopGa4Setting and runs a GA4 Data API report for it via
 * Ga4ReportingClient. Results are cached briefly in Redis, keyed by
 * property_id + a hash of the report params, to absorb bursts of identical
 * requests (e.g. a dashboard re-rendering) without hammering the Data API.
 */
final class FetchGa4Report
{
    use AsObject;

    private const int CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly Ga4ReportingClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $reportParams  GA4 Data API runReport request body.
     * @return array<string, mixed> Decoded runReport JSON response.
     *
     * @throws NotFoundHttpException When the shop has no active GA4 property configured.
     */
    public function handle(User $user, array $reportParams): array
    {
        $setting = $user->ga4Setting;

        if ($setting === null || ! $setting->active) {
            throw new NotFoundHttpException(
                'No active GA4 property is configured for this shop. Add one via '
                .'PUT /api/settings/ga4-property before requesting reports.',
            );
        }

        $cacheKey = 'ga4:report:'.$setting->property_id.':'.md5(json_encode($reportParams) ?: '');

        return Cache::store('redis')->remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->client->runReport($setting->property_id, $reportParams),
        );
    }
}
