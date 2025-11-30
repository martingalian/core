<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove old circuit breaker column from martingalian
        Schema::table('martingalian', function (Blueprint $table) {
            $table->dropColumn('can_dispatch_steps');
        });

        // Add new cooling down flag to martingalian
        // When true, the scheduler stops dispatching new steps entirely
        Schema::table('martingalian', function (Blueprint $table) {
            $table->boolean('is_cooling_down')
                ->default(false)
                ->after('allow_opening_positions')
                ->comment('When true, scheduler stops dispatching steps for safe deployment');
        });
    }

    public function down(): void
    {
        Schema::table('martingalian', function (Blueprint $table) {
            $table->dropColumn('is_cooling_down');
        });

        Schema::table('martingalian', function (Blueprint $table) {
            $table->boolean('can_dispatch_steps')
                ->default(true)
                ->after('allow_opening_positions');
        });
    }
};
