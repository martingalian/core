<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('notification_logs');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not reversible - the old notification_logs table used a different schema
        // than the current throttle_logs table, so we cannot recreate it
    }
};
