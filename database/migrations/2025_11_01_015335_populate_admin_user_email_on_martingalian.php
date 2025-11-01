<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Martingalian\Core\Models\Martingalian;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Populate admin_user_email from environment.
     * This migration runs after admin_user_email column is added.
     */
    public function up(): void
    {
        $martingalian = Martingalian::find(1);

        if (! $martingalian) {
            return;
        }

        // Set admin user email from env if not already set
        if ($martingalian->admin_user_email === null) {
            $adminEmail = env('ADMIN_USER_EMAIL');
            if ($adminEmail) {
                $martingalian->update([
                    'admin_user_email' => $adminEmail,
                ]);
            }
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
