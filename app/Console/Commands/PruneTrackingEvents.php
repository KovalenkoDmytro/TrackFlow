<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TrackingEvent;
use Illuminate\Console\Command;

final class PruneTrackingEvents extends Command
{
    protected $signature = 'tracking:prune
        {--days= : Delete events created before this many days ago}
        {--chunk=10000 : Rows deleted per committed batch}
        {--dry-run : Count eligible rows without deleting them}';

    protected $description = 'Delete tracking events older than the configured retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('tracking.retention_days', 90));
        $chunkSize = (int) $this->option('chunk');

        if ($days < 1 || $chunkSize < 1 || $chunkSize > 50000) {
            $this->error('Days must be at least 1 and chunk must be between 1 and 50000.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $query = TrackingEvent::query()->where('created_at', '<', $cutoff);
        $eligible = (clone $query)->count();

        $this->info("Retention cutoff: {$cutoff->toDateTimeString()}");
        $this->info("Eligible events: {$eligible}");

        if ($this->option('dry-run') || $eligible === 0) {
            return self::SUCCESS;
        }

        $deleted = 0;

        do {
            $ids = (clone $query)->limit($chunkSize)->pluck('id');
            $batch = $ids->isEmpty()
                ? 0
                : TrackingEvent::query()->whereKey($ids)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("Deleted events: {$deleted}");

        return self::SUCCESS;
    }
}
