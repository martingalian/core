<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add composite index on (state, child_block_uuid) for analytics dashboard queries.
 *
 * This index optimizes queries that filter by state and child_block_uuid IS NULL,
 * which are used heavily in the analytics dashboard for counting child steps.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            $table->index(['state', 'child_block_uuid'], 'idx_steps_state_child_block_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            $table->dropIndex('idx_steps_state_child_block_uuid');
        });
    }
};
