<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds memory_usage_mb column to heartbeats table for monitoring
     * WebSocket process memory consumption over time.
     */
    public function up(): void
    {
        Schema::table('heartbeats', function (Blueprint $table): void {
            $table->decimal('memory_usage_mb', 8, 2)
                ->nullable()
                ->after('internal_reconnect_attempts')
                ->comment('Memory usage in MB at last heartbeat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('heartbeats', function (Blueprint $table): void {
            $table->dropColumn('memory_usage_mb');
        });
    }
};
