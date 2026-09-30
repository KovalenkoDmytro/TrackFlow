<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tracking\ProcessTrackingEvent;
use App\Enums\Platform;
use App\Mail\HealthAlertMail;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Detects failures that would otherwise be silent and emails the owner.
 *
 * Limitation: this command runs from the scheduler, so it cannot detect a dead
 * scheduler. Set HEALTHCHECK_PING_URL to an external dead-man's-switch monitor.
 */
final class HealthCheck extends Command
{
    private const ALERTING_CACHE_KEY = 'health:alerting';

    private const CLEAN_RUNS_CACHE_KEY = 'health:clean_runs';

    protected $signature = 'health:check {--no-mail : Dry run, do not send or throttle emails}';

    protected $description = 'Check sync freshness, failed jobs, deliveries, mappings and queue backlog; email on problems';

    public function handle(): int
    {
        $checks = [
            'Google Ads click sync' => $this->checkGoogleAdsSync(...),
            'Failed queue jobs' => $this->checkFailedJobs(...),
            'Delivery failures' => $this->checkDeliveryFailures(...),
            'Dead integrations' => $this->checkDeadIntegrations(...),
            'Integration mappings' => $this->checkMappings(...),
            'Queue backlog' => $this->checkQueueBacklog(...),
        ];

        $rows = [];
        /** @var array<string, string> $problems key => message */
        $problems = [];
        foreach ($checks as $label => $check) {
            $found = $check();
            $rows[] = [$label, $found === [] ? 'OK' : 'PROBLEM'];
            $problems += $found;
        }

        $this->table(['Check', 'Status'], $rows);
        foreach ($problems as $message) {
            Log::warning('health:check problem: '.$message);
            $this->line(' - '.$message);
        }

        if (! $this->option('no-mail')) {
            $this->notify($problems);
        }

        if ($problems === []) {
            $this->ping();

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /** @return array<string, string> */
    private function checkGoogleAdsSync(): array
    {
        $maxAge = (int) config('alerts.google_ads_sync_max_age_hours');
        $problems = [];
        $integrations = PlatformIntegration::query()->with('user:id,name')
            ->where('platform', Platform::GoogleAds)->where('active', true)->get();

        foreach ($integrations as $integration) {
            $last = DB::table('google_ads_click_syncs')
                ->where('platform_integration_id', $integration->getKey())->max('checked_at');

            if ($last === null || now()->parse($last)->lt(now()->subHours($maxAge))) {
                $when = $last === null ? 'has never succeeded' : 'last succeeded '.now()->parse($last)->diffForHumans();
                $problems['google_ads_sync:'.$integration->getKey()]
                    = "Google Ads click sync {$when} (integration {$integration->getKey()}, shop {$integration->user?->name}).";
            }
        }

        return $problems;
    }

    /** @return array<string, string> */
    private function checkFailedJobs(): array
    {
        $payloads = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->pluck('payload');
        if ($payloads->isEmpty()) {
            return [];
        }

        $names = $payloads->map(function (string $payload): string {
            $decoded = json_decode($payload, true);

            return is_array($decoded) && is_string($decoded['displayName'] ?? null) ? $decoded['displayName'] : 'unknown';
        })->countBy()->map(fn (int $n, string $name): string => "{$name} x{$n}")->values()->implode(', ');

        return ['failed_jobs' => "{$payloads->count()} queue job(s) failed in the last hour: {$names}."];
    }

    /** @return array<string, string> */
    private function checkDeliveryFailures(): array
    {
        $minCount = (int) config('alerts.delivery_failure_min_count');
        $minPercent = (int) config('alerts.delivery_failure_min_percent');
        $problems = [];

        // Only `failed` rows whose retries are exhausted count. `partial_failure` is a
        // terminal data condition (Google/Meta rejected the payload, never retried) that
        // says nothing about integration health, and a `failed` row still inside the job's
        // retry/backoff window may yet be delivered.
        $tries = app(ProcessTrackingEvent::class)->tries;

        $stats = PlatformDelivery::query()
            ->selectRaw(
                "platform_integration_id, platform, COUNT(*) as total, SUM(CASE WHEN status = 'failed' AND (attempts >= ? OR updated_at <= ?) THEN 1 ELSE 0 END) as failures",
                [$tries, now()->subMinutes(10)],
            )
            ->where('created_at', '>=', now()->subHour())
            ->groupBy('platform_integration_id', 'platform')
            ->get();

        foreach ($stats as $row) {
            $total = (int) $row->getAttribute('total');
            $failures = (int) $row->getAttribute('failures');
            if ($failures >= $minCount && $failures * 100 >= $minPercent * $total) {
                $problems['delivery:'.$row->platform_integration_id] = "{$failures} of {$total} {$row->platform} deliveries failed in the last hour (integration {$row->platform_integration_id}).";
            }
        }

        return $problems;
    }

    /** @return array<string, string> */
    private function checkDeadIntegrations(): array
    {
        $minCount = (int) config('alerts.dead_integration_min_deliveries');
        $problems = [];

        $stats = PlatformDelivery::query()
            ->selectRaw("platform_integration_id, platform, COUNT(*) as total, SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered")
            ->whereIn('platform_integration_id', PlatformIntegration::query()->where('active', true)->select('id'))
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('platform_integration_id', 'platform')
            ->get();

        foreach ($stats as $row) {
            $total = (int) $row->getAttribute('total');
            if ($total < $minCount || (int) $row->getAttribute('delivered') !== 0) {
                continue;
            }

            $reasons = $this->deliveryReasons((int) $row->platform_integration_id);
            if ($this->onlyDataConditions($reasons, $total)) {
                continue;
            }

            $shop = PlatformIntegration::query()->with('user:id,name')->find($row->platform_integration_id)?->user?->name;
            $where = $shop === null ? '' : " ({$shop})";
            $message = "{$row->platform} integration {$row->platform_integration_id}{$where}: {$total} deliveries in 24h, none delivered.";

            if ($reasons !== []) {
                $code = (string) array_key_first($reasons);
                $message .= " Top reason: {$code} ({$reasons[$code]})";
                $hint = config("alerts.delivery_reason_hints.{$code}.hint");
                $message .= is_string($hint) ? " — {$hint}" : '.';
                if ($code === 'INVALID_CUSTOMER_FOR_CLICK' && ($evidence = $this->clickOwnershipEvidence((int) $row->platform_integration_id)) !== null) {
                    $message .= " {$evidence}";
                }
                if (count($reasons) > 1) {
                    $message .= ' Other reasons: '.collect($reasons)->except($code)->map(fn (int $n, string $c): string => "{$c} ({$n})")->implode(', ').'.';
                }
            }

            $problems['dead_integration:'.$row->platform_integration_id] = $message;
        }

        return $problems;
    }

    /**
     * Primary rejection code per non-delivered row in the last 24h, most frequent first.
     * Older rows without a persisted reason are simply not counted.
     *
     * @return array<string, int> code => rows
     */
    private function deliveryReasons(int $integrationId): array
    {
        $codes = PlatformDelivery::query()
            ->where('platform_integration_id', $integrationId)
            ->where('created_at', '>=', now()->subDay())
            ->where('status', '!=', 'delivered')
            ->whereNotNull('response_body')
            ->limit(2000)
            ->pluck('response_body')
            ->map(function (string $body): ?string {
                $decoded = json_decode($body, true);
                $code = is_array($decoded) && is_array($decoded['codes'] ?? null) ? ($decoded['codes'][0] ?? null) : null;

                return is_string($code) ? $code : null;
            })
            ->filter()
            ->countBy()
            ->sortDesc();

        return $codes->all();
    }

    /**
     * When the click sync has covered enough days and still matched none of the shop's
     * captured click IDs, the connected account demonstrably owns no clicks for this
     * traffic, so say that instead of guessing. Null when the evidence is inconclusive
     * (sync too young, no click IDs, or at least one match).
     */
    private function clickOwnershipEvidence(int $integrationId): ?string
    {
        $userId = PlatformIntegration::query()->whereKey($integrationId)->value('user_id');
        $checkedDays = DB::table('google_ads_click_syncs')->where('platform_integration_id', $integrationId)->count();
        if ($userId === null || $checkedDays < (int) config('alerts.click_ownership_min_synced_days')) {
            return null;
        }

        $events = TrackingEvent::query()->where('user_id', $userId)
            ->where('occurred_at', '>=', now()->subDays(30))
            ->whereNotNull('gclid_hash');
        $tagged = (clone $events)->distinct()->count('gclid_hash');
        $matched = (clone $events)->whereExists(fn ($query) => $query->selectRaw('1')->from('google_ads_clicks')
            ->where('platform_integration_id', $integrationId)
            ->whereColumn('google_ads_clicks.gclid_hash', 'tracking_events.gclid_hash'))->exists();

        if ($tagged === 0 || $matched) {
            return null;
        }

        return "Click matching over {$checkedDays} synced days found none of {$tagged} captured click IDs in the connected account: "
            ."the ads sending this traffic run in a different Google Ads account. Run `php artisan google-ads:find-click-owner {$integrationId} --mcc=<manager id>` to locate it.";
    }

    /**
     * True when every row carries a code and all codes are normal data conditions
     * (expired/too-recent/duplicate events) that reconnecting cannot fix.
     *
     * @param  array<string, int>  $reasons
     */
    private function onlyDataConditions(array $reasons, int $total): bool
    {
        if ($reasons === [] || array_sum($reasons) < $total) {
            return false;
        }

        foreach (array_keys($reasons) as $code) {
            if (config("alerts.delivery_reason_hints.{$code}.alert", true) !== false) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function checkMappings(): array
    {
        $problems = [];
        $integrations = PlatformIntegration::query()->with('user:id,name')->where('active', true)
            ->whereDoesntHave('conversionActionMappings', fn ($q) => $q->where('active', true))->get();

        foreach ($integrations as $integration) {
            $problems['mappings:'.$integration->getKey()]
                = "Active {$integration->platform->value} integration {$integration->getKey()} (shop {$integration->user?->name}) has no active conversion action mappings; nothing is delivered.";
        }

        return $problems;
    }

    /** @return array<string, string> */
    private function checkQueueBacklog(): array
    {
        $maxMinutes = (int) config('alerts.queue_backlog_max_age_minutes');
        $oldest = DB::table('jobs')->min('created_at');

        if ($oldest !== null && now()->timestamp - (int) $oldest > $maxMinutes * 60) {
            return ['queue_backlog' => 'Oldest queued job has waited '.now()->createFromTimestamp((int) $oldest)->diffForHumans(syntax: true)." (limit {$maxMinutes} min); queue worker may be down."];
        }

        return [];
    }

    /** @param  array<string, string>  $problems */
    private function notify(array $problems): void
    {
        $to = config('alerts.email');
        if (! is_string($to) || $to === '') {
            Log::warning('health:check: ALERT_EMAIL is not set, skipping alert email.');

            return;
        }

        if ($problems === []) {
            // Send "recovered" only after several consecutive clean runs to avoid flapping.
            $previous = Cache::get(self::ALERTING_CACHE_KEY);
            if (is_string($previous)) {
                $clean = (int) Cache::get(self::CLEAN_RUNS_CACHE_KEY, 0) + 1;
                if ($clean >= (int) config('alerts.recovery_runs')) {
                    Cache::forget(self::ALERTING_CACHE_KEY);
                    Cache::forget(self::CLEAN_RUNS_CACHE_KEY);
                    Cache::forget($previous);
                    Mail::to($to)->send(new HealthAlertMail([], recovered: true));
                } else {
                    Cache::put(self::CLEAN_RUNS_CACHE_KEY, $clean, now()->addDays(7));
                }
            }

            return;
        }

        Cache::forget(self::CLEAN_RUNS_CACHE_KEY);

        $keys = array_keys($problems);
        sort($keys);
        $throttleKey = 'health:alert:'.sha1(implode('|', $keys));

        // Cache::add is atomic: only the first caller within the window wins.
        if (! Cache::add($throttleKey, true, now()->addHours((int) config('alerts.throttle_hours')))) {
            return;
        }

        Cache::put(self::ALERTING_CACHE_KEY, $throttleKey, now()->addDays(7));
        Mail::to($to)->send(new HealthAlertMail(array_values($problems)));
    }

    private function ping(): void
    {
        $url = config('alerts.ping_url');
        if (! is_string($url) || $url === '') {
            return;
        }

        try {
            Http::timeout(5)->get($url);
        } catch (\Throwable) {
            // A failing monitor endpoint must never break the check.
        }
    }
}
