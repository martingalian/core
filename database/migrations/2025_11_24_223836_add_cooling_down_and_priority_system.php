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
        Schema::table('martingalian', function (Blueprint $table) {
            $table->boolean('is_cooling_down')
                ->default(false)
                ->after('allow_opening_positions')
                ->comment('When true, only critical priority steps are dispatched');
        });

        // Add can_cool_down column to steps table
        // true (default) = step can be paused during cooldown
        // false = step must be flushed even during cooldown (critical operations)
        Schema::table('steps', function (Blueprint $table) {
            $table->boolean('can_cool_down')
                ->default(true)
                ->after('priority')
                ->comment('When false, step dispatches even during cooldown');
        });
    }

    public function down(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            $table->dropColumn('can_cool_down');
        });

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
