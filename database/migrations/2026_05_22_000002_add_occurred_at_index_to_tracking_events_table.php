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
            $table->index(['user_id', 'occurred_at'], 'tracking_events_user_id_occurred_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_events', function (Blueprint $table): void {
            $table->dropIndex('tracking_events_user_id_occurred_at_index');
        });
    }
};
