<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the shared-OAuth GA4 reporting feature's tables.
 *
 * The feature (shared operator Google OAuth account + per-shop GA4 property
 * assignment for GA4 Data API reporting) was removed — tracking events are
 * already stored in this app's own database, so pulling duplicate data from
 * GA4 was not needed. The original create migrations
 * (2026_07_20_140001_create_oauth_credentials_table.php and
 * 2026_07_20_140002_create_shop_ga4_settings_table.php) are left untouched
 * per this project's convention of never modifying existing migrations —
 * this migration instead drops what they created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('shop_ga4_settings');
        Schema::dropIfExists('oauth_credentials');
    }

    public function down(): void
    {
        Schema::create('oauth_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->unique();
            $table->text('refresh_token')->nullable();
            $table->json('scopes')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_ga4_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('property_id');
            $table->string('property_display_name')->nullable();
            $table->string('property_timezone')->nullable();
            $table->string('property_currency')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
    }
};
