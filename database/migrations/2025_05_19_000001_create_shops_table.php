<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table): void {
            $table->id();
            $table->string('shopify_domain')->unique();
            $table->text('access_token');
            $table->string('pixel_secret', 64)->nullable();
            $table->string('shopify_pixel_id')->nullable();
            $table->string('plan')->default('free');
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
