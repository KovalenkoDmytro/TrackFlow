<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\TrackingEventData;
use App\Enums\Platform;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Services\GoogleAdsClient;
use Illuminate\Console\Command;

final class BackfillGoogleAdsConversions extends Command
{
    protected $signature = 'google-ads:backfill
        {integration : Platform integration ID}
        {--from= : Inclusive occurred_at timestamp}
        {--to= : Inclusive occurred_at timestamp}
        {--dry-run : Count eligible events without sending them}';

    protected $description = 'Upload previously unattempted GCLID events to a Google Ads integration';

    public function handle(GoogleAdsClient $client): int
    {
        $integration = PlatformIntegration::query()
            ->with(['user', 'conversionActionMappings'])
            ->findOrFail((int) $this->argument('integration'));

        if ($integration->platform !== Platform::GoogleAds || ! $integration->active) {
            $this->error('The selected integration is not an active Google Ads integration.');

            return self::FAILURE;
        }

        $query = TrackingEvent::query()
            ->where('user_id', $integration->user_id)
            ->whereNotNull('gclid')
            ->whereDoesntHave('platformDeliveries', fn ($q) => $q
                ->where('platform_integration_id', $integration->getKey()));

        if ($this->option('from')) {
            $query->where('occurred_at', '>=', $this->option('from'));
        }

        if ($this->option('to')) {
            $query->where('occurred_at', '<=', $this->option('to'));
        }

        $eligible = (clone $query)->count();
        $this->info("Eligible events: {$eligible}");

        if ($this->option('dry-run') || $eligible === 0) {
            return self::SUCCESS;
        }

        $mappings = $integration->conversionActionMappings
            ->where('active', true)
            ->keyBy('event');
        $credentials = json_decode($integration->credentials, true);
        $counts = ['delivered' => 0, 'partial_failure' => 0, 'failed' => 0, 'no_mapping' => 0];

        $query->chunkById(50, function ($events) use ($client, $credentials, $integration, $mappings, &$counts): void {
            foreach ($events as $event) {
                $mapping = $mappings->get($event->event);

                if ($mapping === null) {
                    $counts['no_mapping']++;

                    continue;
                }

                $delivery = PlatformDelivery::query()->create([
                    'tracking_event_id' => $event->getKey(),
                    'platform_integration_id' => $integration->getKey(),
                    'platform' => Platform::GoogleAds->value,
                    'status' => 'queued',
                ]);

                try {
                    $success = $client->uploadConversion(
                        $credentials,
                        $this->toData($event, $integration->user->name),
                        $mapping,
                    );
                    $status = $success ? 'delivered' : 'partial_failure';
                    $delivery->update([
                        'status' => $status,
                        'attempts' => 1,
                        'sent_at' => now(),
                    ]);
                    $counts[$status]++;
                } catch (\RuntimeException $exception) {
                    $delivery->update([
                        'status' => 'failed',
                        'attempts' => 1,
                        'response_body' => $exception->getMessage(),
                    ]);
                    $counts['failed']++;
                }
            }
        }, 'id');

        $this->table(
            ['Status', 'Count'],
            collect($counts)->map(fn ($count, $status) => [$status, $count])->values()->all(),
        );

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function toData(TrackingEvent $event, string $shopDomain): TrackingEventData
    {
        return new TrackingEventData(
            shopDomain: $shopDomain,
            event: $event->event,
            value: (float) $event->value,
            currency: $event->currency,
            transactionId: $event->transaction_id,
            gclid: $event->gclid,
            fbp: $event->fbp,
            fbc: $event->fbc,
            ttclid: $event->ttclid,
            gaClientId: $event->ga_client_id,
            ip: $event->ip,
            userAgent: $event->user_agent,
            idempotencyKey: $event->idempotency_key,
            occurredAt: $event->occurred_at->toDateTimeImmutable(),
        );
    }
}
