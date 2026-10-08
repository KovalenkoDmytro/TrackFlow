<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\PartialFailure;
use App\Data\TrackingEventData;
use App\Enums\Platform;
use App\Models\PlatformDelivery;
use App\Models\PlatformIntegration;
use App\Models\TrackingEvent;
use App\Services\GoogleAdsClient;
use App\Services\GoogleAdsConversionWindow;
use App\Services\GoogleAdsOtherAccountClassifier;
use Illuminate\Console\Command;

final class BackfillGoogleAdsConversions extends Command
{
    protected $signature = 'google-ads:backfill
        {integration : Platform integration ID}
        {--from= : Inclusive occurred_at timestamp}
        {--to= : Inclusive occurred_at timestamp}
        {--dry-run : Count would-upload and would-expire events without sending or writing anything}';

    protected $description = 'Upload previously unattempted GCLID events to a Google Ads integration';

    public function handle(GoogleAdsClient $client, GoogleAdsOtherAccountClassifier $classifier, GoogleAdsConversionWindow $window): int
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

        if ($eligible === 0) {
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $mappings = $integration->conversionActionMappings
            ->where('active', true)
            ->keyBy('event');
        $credentials = json_decode($integration->credentials, true);
        $counts = ['delivered' => 0, 'partial_failure' => 0, 'other_account' => 0, 'failed' => 0, 'no_mapping' => 0, PlatformDelivery::STATUS_EXPIRED => 0];
        $wouldUpload = 0;

        $query->chunkById(50, function ($events) use ($client, $classifier, $window, $dryRun, $credentials, $integration, $mappings, &$counts, &$wouldUpload): void {
            foreach ($events as $event) {
                $mapping = $mappings->get($event->event);

                if ($mapping === null) {
                    $counts['no_mapping']++;

                    continue;
                }

                $verdict = $window->evaluate($integration, $event, $mapping);

                if ($dryRun) {
                    $verdict['eligible'] ? $wouldUpload++ : $counts[PlatformDelivery::STATUS_EXPIRED]++;

                    continue;
                }

                $delivery = PlatformDelivery::query()->create([
                    'tracking_event_id' => $event->getKey(),
                    'platform_integration_id' => $integration->getKey(),
                    'platform' => Platform::GoogleAds->value,
                    'status' => 'queued',
                ]);

                if ($classifier->isOwnershipKnown($integration, $event)) {
                    $delivery->update([
                        'status' => GoogleAdsOtherAccountClassifier::STATUS,
                        'response_body' => $classifier->skippedBody($classifier->customerId($integration)),
                    ]);
                    $counts[GoogleAdsOtherAccountClassifier::STATUS]++;

                    continue;
                }

                // Outside the conversion window Google would answer EXPIRED_EVENT: record it
                // locally so the event is never selected again and no upload is made.
                if (! $verdict['eligible']) {
                    $window->markSkipped($delivery, 'backfill', $integration, $event, $verdict['age_days'], $verdict['window_days']);
                    $counts[PlatformDelivery::STATUS_EXPIRED]++;

                    continue;
                }

                try {
                    $success = $client->uploadConversion(
                        $credentials,
                        $this->toData($event, $integration->user->name),
                        $mapping,
                    );
                    $failure = $success ? null : $client->partialFailure();
                    $status = match (true) {
                        $success => 'delivered',
                        $classifier->isOtherAccountFailure($integration, $event, $failure) => GoogleAdsOtherAccountClassifier::STATUS,
                        $failure?->primaryCode() === GoogleAdsConversionWindow::GOOGLE_CODE => PlatformDelivery::STATUS_EXPIRED,
                        default => 'partial_failure',
                    };
                    $delivery->update([
                        'status' => $status,
                        'attempts' => 1,
                        'sent_at' => now(),
                        ...($failure instanceof PartialFailure ? [
                            'response_code' => $failure->httpStatus,
                            'response_body' => $status === GoogleAdsOtherAccountClassifier::STATUS
                                ? $classifier->body($failure, $classifier->customerId($integration))
                                : $failure->toJson(),
                        ] : []),
                    ]);
                    $counts[$status]++;

                    if ($status === PlatformDelivery::STATUS_EXPIRED) {
                        $window->log(
                            'google_ads.conversion_expired',
                            'google_response',
                            $integration,
                            $event,
                            $verdict['age_days'],
                            $verdict['window_days'],
                        );
                    }
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

        if ($dryRun) {
            $this->info("Dry run: would upload {$wouldUpload}, would mark expired {$counts[PlatformDelivery::STATUS_EXPIRED]}, no mapping {$counts['no_mapping']}.");

            return self::SUCCESS;
        }

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
