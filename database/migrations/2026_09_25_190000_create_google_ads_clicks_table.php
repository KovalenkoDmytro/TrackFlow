<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_events', function (Blueprint $table): void {
            $table->char('gclid_hash', 64)->nullable()->index();
        });
        Schema::create('google_ads_clicks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_integration_id')->constrained()->cascadeOnDelete();
            $table->string('customer_id', 10);
            $table->char('gclid_hash', 64);
            $table->date('click_date');
            $table->dateTime('day_start_utc');
            $table->dateTime('checked_at');
            $table->unique(['platform_integration_id', 'customer_id', 'click_date', 'gclid_hash'], 'google_ads_click_identity');
            $table->index(['platform_integration_id', 'customer_id', 'gclid_hash'], 'google_ads_click_lookup');
        });
        Schema::create('google_ads_click_syncs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_integration_id')->constrained()->cascadeOnDelete();
            $table->string('customer_id', 10);
            $table->date('click_date');
            $table->dateTime('checked_at');
            $table->unique(['platform_integration_id', 'customer_id', 'click_date'], 'google_ads_click_sync_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_click_syncs');
        Schema::dropIfExists('google_ads_clicks');
        Schema::table('tracking_events', fn (Blueprint $table) => $table->dropColumn('gclid_hash'));
    }
};
