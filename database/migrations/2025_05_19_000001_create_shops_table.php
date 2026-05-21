<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('shops');

        Schema::table('users', function (Blueprint $table): void {
            $table->string('tracking_secret', 64)->nullable()->after('remember_token');
            $table->string('shopify_pixel_id')->nullable()->after('tracking_secret');
            $table->string('plan')->default('free')->after('shopify_pixel_id');
            $table->timestamp('installed_at')->nullable()->after('plan');
            $table->timestamp('uninstalled_at')->nullable()->after('installed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['tracking_secret', 'shopify_pixel_id', 'plan', 'installed_at', 'uninstalled_at']);
        });

        Schema::create('shops', function (Blueprint $table): void {
            $table->id();
            $table->string('shopify_domain')->unique();
            $table->text('access_token');
            $table->string('tracking_secret', 64)->nullable();
            $table->string('shopify_pixel_id')->nullable();
            $table->string('plan')->default('free');
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamps();
        });
    }
};
