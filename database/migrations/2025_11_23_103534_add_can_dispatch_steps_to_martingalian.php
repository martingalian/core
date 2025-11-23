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
        Schema::table('martingalian', function (Blueprint $table) {
            $table->boolean('can_dispatch_steps')
                ->default(true)
                ->after('allow_opening_positions')
                ->comment('Global circuit breaker: stops step dispatcher from dispatching new steps (allows graceful Horizon restarts)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('martingalian', function (Blueprint $table) {
            $table->dropColumn('can_dispatch_steps');
        });
    }
};
