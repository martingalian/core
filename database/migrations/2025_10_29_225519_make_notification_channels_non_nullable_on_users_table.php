<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, update all existing users with null notification_channels to have ['mail', 'pushover'] as default
        Illuminate\Support\Facades\DB::table('users')
            ->whereNull('notification_channels')
            ->update(['notification_channels' => json_encode(['mail', 'pushover'])]);

        // Now make the column non-nullable (JSON columns cannot have defaults in MySQL)
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_channels')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_channels')->nullable()->change();
        });
    }
};
