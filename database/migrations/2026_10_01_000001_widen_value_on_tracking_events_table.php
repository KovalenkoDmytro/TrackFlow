<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_MAX = '99999999.99';

    /**
     * Shopify Markets sends prices in the visitor's presentment currency
     * (e.g. 195,848,000 LBP), which overflows decimal(10,2).
     *
     * Note: on MySQL this ALTER rebuilds (copies) the table, so run it off-peak.
     */
    public function up(): void
    {
        Schema::table('tracking_events', function (Blueprint $table): void {
            $table->decimal('value', 18, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        // Cap oversized rows first so narrowing the column cannot itself fail.
        DB::table('tracking_events')
            ->where('value', '>', self::OLD_MAX)
            ->update(['value' => self::OLD_MAX]);

        Schema::table('tracking_events', function (Blueprint $table): void {
            $table->decimal('value', 10, 2)->default(0)->change();
        });
    }
};
