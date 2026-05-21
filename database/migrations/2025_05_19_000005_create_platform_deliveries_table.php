<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tracking_event_id');
            $table->foreign('tracking_event_id')->references('id')->on('tracking_events')->cascadeOnDelete();
            $table->foreignId('platform_integration_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('status')->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['tracking_event_id']);
            $table->index(['status', 'created_at']);
            $table->index(['platform_integration_id', 'status', 'created_at'], 'pd_integration_status_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_deliveries');
    }
};
