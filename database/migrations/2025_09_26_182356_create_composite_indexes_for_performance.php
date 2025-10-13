<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add composite indexes for common access patterns on steps.
     */
    public function up(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            // For queries fetching steps of a given relatable (model) + state, ordered by index
            $table->index(
                ['relatable_type', 'relatable_id', 'state', 'index'],
                'idx_steps_rel_state_idx'
            );

            // For queries filtering by state with time windows (recent or historical)
            $table->index(
                ['state', 'created_at'],
                'idx_steps_state_created'
            );

            // For queries that might use scheduling/dispatch order
            $table->index(
                ['dispatch_after', 'state'],
                'idx_steps_dispatch_state'
            );
        });
    }
};
