<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add composite indexes for common access patterns and fast TTL purges.
     *
     * Notes:
     * - We favor (created_at, id) so MySQL can satisfy
     *   "ORDER BY created_at, id LIMIT N" from the index during batch purges.
     * - For api_request_logs we also add a relation-oriented index used by your
     *   queries that filter by relatable_type/relatable_id and time window.
     * - Style follows your example (Schema Builder only, up() only).
     */
    public function up(): void
    {
        // ---------------------------------------------------------------------
        // steps
        // ---------------------------------------------------------------------
        Schema::table('steps', function (Blueprint $table) {
            // For queries fetching steps of a given relatable (model) + state, ordered by index
            $table->index(
                ['relatable_type', 'relatable_id', 'state', 'index'],
                'idx_p_steps_rel_state_idx'
            );

            // For queries filtering by state with time windows (recent or historical)
            $table->index(
                ['state', 'created_at'],
                'idx_p_steps_state_created'
            );

            // For queries that might use scheduling/dispatch order
            $table->index(
                ['dispatch_after', 'state'],
                'idx_p_steps_dispatch_state'
            );

            // For fast TTL purge batches (DELETE … WHERE created_at<=? ORDER BY created_at,id LIMIT N)
            $table->index(
                ['created_at', 'id'],
                'idx_p_steps_created_id'
            );
        });

        // ---------------------------------------------------------------------
        // steps_dispatcher_ticks
        // ---------------------------------------------------------------------
        Schema::table('steps_dispatcher_ticks', function (Blueprint $table) {
            // For fast TTL purge batches on ticks
            $table->index(
                ['created_at', 'id'],
                'idx_p_sdt_created_id'
            );
        });

        // ---------------------------------------------------------------------
        // api_request_logs
        // ---------------------------------------------------------------------
        Schema::table('api_request_logs', function (Blueprint $table) {
            // For fast TTL purge batches on logs
            $table->index(
                ['created_at', 'id'],
                'idx_p_arl_created_id'
            );

            // REMOVED: Duplicate of api_req_logs_rel_idx from 2025_09_11_224819
            // $table->index(
            //     ['relatable_type', 'relatable_id', 'created_at'],
            //     'idx_p_arl_rel_created'
            // );
        });

        // ---------------------------------------------------------------------
        // price_history
        // ---------------------------------------------------------------------
        Schema::table('price_history', function (Blueprint $table) {
            // For fast TTL purge batches on historical price rows
            $table->index(
                ['created_at', 'id'],
                'idx_p_ph_created_id'
            );
        });
    }
};
