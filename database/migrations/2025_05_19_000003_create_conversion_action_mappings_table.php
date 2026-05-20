<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_action_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_integration_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->string('external_action_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['platform_integration_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_action_mappings');
    }
};
