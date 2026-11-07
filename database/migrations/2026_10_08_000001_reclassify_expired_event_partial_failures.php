<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Google Ads partial failures whose primary code is EXPIRED_EVENT are a late-event data
     * condition, reported as their own `expired` status. The moved ids are remembered inside
     * response_body (`previous_status`) so down() restores exactly these rows.
     */
    public function up(): void
    {
        DB::table('platform_deliveries')
            ->where('platform', 'google_ads')
            ->where('status', 'partial_failure')
            ->where('response_body', 'like', '%EXPIRED_EVENT%')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $body = json_decode((string) $row->response_body, true);

                    if (! is_array($body) || ! is_array($body['codes'] ?? null) || ($body['codes'][0] ?? null) !== 'EXPIRED_EVENT') {
                        continue;
                    }

                    DB::table('platform_deliveries')->where('id', $row->id)->update([
                        'status' => 'expired',
                        'response_body' => json_encode(
                            $body + ['previous_status' => 'partial_failure'],
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                        ),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('platform_deliveries')
            ->where('platform', 'google_ads')
            ->where('status', 'expired')
            ->where('response_body', 'like', '%"previous_status":"partial_failure"%')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $body = json_decode((string) $row->response_body, true);

                    if (! is_array($body) || ($body['previous_status'] ?? null) !== 'partial_failure') {
                        continue;
                    }

                    unset($body['previous_status']);

                    DB::table('platform_deliveries')->where('id', $row->id)->update([
                        'status' => 'partial_failure',
                        'response_body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });
    }
};
