<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Martingalian\Core\Database\Seeders\StaleDispatchedStepsNotificationSeeder;
use Martingalian\Core\Models\Notification;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Seed the stale_dispatched_steps_detected notification canonical
        (new StaleDispatchedStepsNotificationSeeder)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the stale_dispatched_steps_detected notification canonical
        Notification::where('canonical', 'stale_dispatched_steps_detected')->delete();
    }
};
