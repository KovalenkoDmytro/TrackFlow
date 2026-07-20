<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Ga4\FetchGa4Report;
use App\Exceptions\GoogleOAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ga4\RunGa4ReportRequest;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * JSON API surface for the GA4 Data API reporting section of the React SPA.
 *
 * Delegates to FetchGa4Report, which resolves the authenticated shop's own
 * ShopGa4Setting::property_id and calls the GA4 Data API using the single
 * shared operator OAuth token (Ga4TokenProvider) — the shop itself never
 * needs its own OAuth credentials for this feature.
 */
final class Ga4ReportingApiController extends Controller
{
    private const array METRICS = ['sessions', 'activeUsers', 'conversions', 'totalRevenue'];

    public function __construct(
        private readonly FetchGa4Report $fetchGa4Report,
    ) {}

    /**
     * Return basic GA4 metrics (sessions, active users, conversions, revenue)
     * for the authenticated shop's configured property over the given date range.
     */
    public function show(RunGa4ReportRequest $request): JsonResponse
    {
        $shop = $request->user();

        $reportParams = [
            'dateRanges' => [
                [
                    'startDate' => (string) $request->validated('start_date'),
                    'endDate' => (string) $request->validated('end_date'),
                ],
            ],
            'metrics' => array_map(static fn (string $name): array => ['name' => $name], self::METRICS),
        ];

        try {
            $report = $this->fetchGa4Report->handle($shop, $reportParams);
        } catch (NotFoundHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (GoogleOAuthException $e) {
            Log::warning('Ga4ReportingApiController: GA4 OAuth error', [
                'user_id' => $shop->getKey(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'GA4 reporting is temporarily unavailable. Please try again shortly.',
            ], 424);
        } catch (LockTimeoutException $e) {
            Log::warning('Ga4ReportingApiController: token refresh lock timed out', [
                'user_id' => $shop->getKey(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'GA4 reporting is busy refreshing credentials. Please try again shortly.',
            ], 424);
        } catch (\RuntimeException $e) {
            Log::error('Ga4ReportingApiController: GA4 Data API error', [
                'user_id' => $shop->getKey(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'GA4 reporting is temporarily unavailable. Please try again shortly.',
            ], 502);
        }

        return response()->json(['report' => $report]);
    }
}
