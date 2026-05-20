<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_integrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->boolean('active')->default(false);
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_integrations');
    }
};
