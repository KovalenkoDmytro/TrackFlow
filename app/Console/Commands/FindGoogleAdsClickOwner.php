<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Services\GoogleAdsClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Read-only diagnostic: finds which Google Ads account under a manager (MCC)
 * owns the click IDs a shop captured, when the connected account owns none of them
 * (INVALID_CUSTOMER_FOR_CLICK). Nothing is written or changed in Google Ads.
 */
final class FindGoogleAdsClickOwner extends Command
{
    protected $signature = 'google-ads:find-click-owner
        {integration : Google Ads integration id}
        {--mcc= : Manager account id to search under (defaults to the integration\'s MCC id)}
        {--days=14 : Recent calendar days of clicks and events to check, 1–90}
        {--sample=1000 : Most recent distinct click ids to look for}
        {--max-accounts=100 : Most active accounts to check, busiest first}';

    protected $description = 'Find which Google Ads account under an MCC owns a shop\'s captured click IDs (read-only)';

    public function handle(GoogleAdsClient $client): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        $sample = filter_var($this->option('sample'), FILTER_VALIDATE_INT);
        $maxAccounts = filter_var($this->option('max-accounts'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 90 || $sample === false || $sample < 1 || $maxAccounts === false || $maxAccounts < 1) {
            $this->error('Days must be between 1 and 90; sample and max-accounts must be positive.');

            return self::FAILURE;
        }

        $integration = PlatformIntegration::query()->where('platform', Platform::GoogleAds)
            ->find($this->argument('integration'));
        if (! $integration instanceof PlatformIntegration) {
            $this->error('Google Ads integration not found.');

            return self::FAILURE;
        }

        $credentials = json_decode($integration->credentials, true);
        $mcc = preg_replace('/\D/', '', (string) ($this->option('mcc') ?: ($credentials['mcc_id'] ?? '')));
        if (! is_array($credentials) || ! preg_match('/^\d{10}$/', (string) $mcc)) {
            $this->error('Provide the manager account with --mcc=1234567890 (the integration has no MCC id).');

            return self::FAILURE;
        }

        // Hash -> true, keyed like google_ads_clicks so whitespace and case behave the same.
        $wanted = TrackingEvent::query()->where('user_id', $integration->user_id)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->whereNotNull('gclid')->whereRaw("TRIM(gclid) <> ''")
            ->orderByDesc('occurred_at')->limit($sample * 3)->pluck('gclid')
            ->map(fn (string $gclid): string => hash('sha256', trim($gclid)))
            ->unique()->take($sample)->flip()->map(fn (): bool => true)->all();

        if ($wanted === []) {
            $this->warn("No click IDs captured for this shop in the last {$days} days.");

            return self::SUCCESS;
        }
        $this->info(count($wanted).' distinct click IDs to locate.');

        try {
            $token = $client->getAccessToken($credentials['oauth']);
            $from = CarbonImmutable::now('UTC')->subDays($days)->toDateString();
            $to = CarbonImmutable::now('UTC')->toDateString();
            $managerCredentials = ['customer_id' => $mcc, 'mcc_id' => $mcc, 'oauth' => $credentials['oauth']];

            // customer_client cannot be combined with metrics, so list the hierarchy first
            // and read each account's clicks from its own customer resource below.
            $rows = $client->report($managerCredentials, $token,
                'SELECT customer_client.id, customer_client.descriptive_name FROM customer_client '
                ."WHERE customer_client.manager = FALSE AND customer_client.status = 'ENABLED'");
        } catch (\Throwable $e) {
            Log::error('google-ads:find-click-owner failed', ['integration' => $integration->getKey(), 'message' => $e->getMessage()]);
            $this->error('Could not list accounts under the manager. See the log entry "google-ads:find-click-owner failed" for the reason.');

            return self::FAILURE;
        }

        $listed = collect($rows)
            ->map(fn (array $row): array => [
                'id' => (string) ($row['customerClient']['id'] ?? ''),
                'name' => (string) ($row['customerClient']['descriptiveName'] ?? ''),
            ])
            ->filter(fn (array $a): bool => preg_match('/^\d{10}$/', $a['id']) === 1)
            ->unique('id')->values();
        $this->info("{$listed->count()} account(s) under the manager; finding those with clicks in the last {$days} days.");

        $accounts = $listed->map(function (array $account) use ($client, $token, $mcc, $credentials, $from, $to): ?array {
            try {
                $clicks = $client->report(['customer_id' => $account['id'], 'mcc_id' => $mcc, 'oauth' => $credentials['oauth']], $token,
                    "SELECT metrics.clicks FROM customer WHERE segments.date BETWEEN '{$from}' AND '{$to}'");
            } catch (\Throwable $e) {
                Log::warning('google-ads:find-click-owner account skipped', ['account' => $account['id'], 'message' => $e->getMessage()]);

                return null;
            }

            return $account + ['clicks' => (int) array_sum(array_map(fn (array $r): int => (int) ($r['metrics']['clicks'] ?? 0), $clicks))];
        })
            ->filter(fn (?array $a): bool => $a !== null && $a['clicks'] > 0)
            ->sortByDesc('clicks')->take($maxAccounts)->values();

        $this->info("{$accounts->count()} account(s) with clicks; checking their click reports.");

        $found = [];
        $pending = $wanted;
        foreach ($accounts as $account) {
            if ($pending === []) {
                break;
            }
            $matches = 0;
            try {
                $accountCredentials = ['customer_id' => $account['id'], 'mcc_id' => $mcc, 'oauth' => $credentials['oauth']];
                for ($offset = 0; $offset <= $days; $offset++) {
                    $date = CarbonImmutable::now('UTC')->subDays($offset)->toDateString();
                    foreach ($client->report($accountCredentials, $token, "SELECT click_view.gclid FROM click_view WHERE segments.date = '{$date}'") as $row) {
                        $gclid = $row['clickView']['gclid'] ?? null;
                        $hash = is_string($gclid) ? hash('sha256', trim($gclid)) : null;
                        if ($hash !== null && isset($pending[$hash])) {
                            unset($pending[$hash]);
                            $matches++;
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('google-ads:find-click-owner account skipped', ['account' => $account['id'], 'message' => $e->getMessage()]);
                $this->warn("Account {$account['id']} skipped (no access or report error).");

                continue;
            }
            if ($matches > 0) {
                $found[] = [$account['id'], $account['name'], $matches, $account['clicks']];
            }
        }

        if ($found === []) {
            $this->warn('No account under this manager owns any of these click IDs. The ads may run outside this MCC, or the clicks are older than the window.');

            return self::SUCCESS;
        }

        usort($found, fn (array $a, array $b): int => $b[2] <=> $a[2]);
        $this->table(['Customer ID', 'Name', 'Click IDs matched', 'Clicks in window'], $found);
        $this->info(count($wanted) - count($pending).' of '.count($wanted).' click IDs located. Connect the top account (Customer ID above) to this shop.');

        return self::SUCCESS;
    }
}
