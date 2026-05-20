<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->decimal('value', 10, 2)->default(0);
            $table->string('currency', 3)->default('CAD');
            $table->string('transaction_id')->nullable();
            $table->string('gclid')->nullable();
            $table->string('fbp')->nullable();
            $table->string('fbc')->nullable();
            $table->string('ttclid')->nullable();
            $table->string('ga_client_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['shop_id', 'idempotency_key']);
            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_events');
    }
};
