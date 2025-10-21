<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Performance indexes for StepDispatcher optimization.
     *
     * These indexes target specific query patterns in the dispatch cycle:
     * - Pending step selection with group filtering
     * - Previous index validation for sequential execution
     * - Parent-child relationship traversal
     * - Nested block hierarchy queries
     */
    public function up(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            // Hot path: Pending step dispatch with group/type filtering
            // Supports: WHERE state=X AND group=Y AND dispatch_after<NOW() AND type=Z
            // Note: Extends idx_steps_group_state_dispatch_after by adding type
            $table->index(['state', 'group', 'dispatch_after', 'type'], 'idx_steps_state_group_dispatch_type');

            // Block traversal: Sequential execution validation
            // Supports: WHERE block_uuid=X AND index=Y AND type=Z ORDER BY state
            // Optimizes previousIndexIsConcluded() calls
            $table->index(['block_uuid', 'index', 'type', 'state'], 'idx_steps_block_index_type_state');

            // Parent lookup: Child step validation
            // Supports: WHERE child_block_uuid=X AND state IN (...)
            // Optimizes parentStep() and isChild() queries
            $table->index(['child_block_uuid', 'state'], 'idx_steps_child_uuid_state');

            // Nested block hierarchy traversal
            // Supports recursive CTE queries in collectAllNestedChildBlocks()
            // Optimizes: WHERE block_uuid IN (...) AND child_block_uuid IS NOT NULL
            $table->index(['block_uuid', 'child_block_uuid'], 'idx_steps_block_child_uuids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            $table->dropIndex('idx_steps_state_group_dispatch_type');
            $table->dropIndex('idx_steps_block_index_type_state');
            $table->dropIndex('idx_steps_child_uuid_state');
            $table->dropIndex('idx_steps_block_child_uuids');
        });
    }
};
