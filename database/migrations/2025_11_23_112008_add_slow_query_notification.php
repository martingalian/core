<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Martingalian\Core\Database\Seeders\SlowQueryNotificationSeeder;
use Martingalian\Core\Models\Notification;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Seed the slow_query_detected notification canonical
        (new SlowQueryNotificationSeeder)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the slow_query_detected notification canonical
        Notification::where('canonical', 'slow_query_detected')->delete();
    }
};
