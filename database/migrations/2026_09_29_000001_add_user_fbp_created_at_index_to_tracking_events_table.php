<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Serves the Meta fbc lookup (shop + fbp, newest first). The existing
        // (user_id, created_at) index would scan every event of the shop in the window.
        Schema::table('tracking_events', function (Blueprint $table): void {
            $table->index(['user_id', 'fbp', 'created_at'], 'tracking_events_user_id_fbp_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_events', function (Blueprint $table): void {
            $table->dropIndex('tracking_events_user_id_fbp_created_at_index');
        });
    }
};
