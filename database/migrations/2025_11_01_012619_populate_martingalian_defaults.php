<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Martingalian\Core\Models\Martingalian;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Set default values for Martingalian record from environment/config.
     * This migration runs after notification_channels, admin_pushover_user_key, and admin_pushover_application_key columns are added.
     */
    public function up(): void
    {
        $martingalian = Martingalian::find(1);

        if (! $martingalian) {
            return;
        }

        $updates = [];

        // Set notification channels if not already set
        if ($martingalian->notification_channels === null) {
            $updates['notification_channels'] = ['pushover', 'mail'];
        }

        // Set admin pushover user key from config if not already set
        if ($martingalian->admin_pushover_user_key === null) {
            $adminPushoverKey = config('martingalian.admin_user_pushover_key');
            if ($adminPushoverKey) {
                $updates['admin_pushover_user_key'] = $adminPushoverKey;
            }
        }

        // Set admin pushover application key from env if not already set
        if ($martingalian->admin_pushover_application_key === null) {
            $appKey = env('PUSHOVER_APPLICATION_KEY');
            if ($appKey) {
                $updates['admin_pushover_application_key'] = $appKey;
            }
        }

        if (! empty($updates)) {
            $martingalian->update($updates);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to reverse - setting defaults is idempotent
    }
};
