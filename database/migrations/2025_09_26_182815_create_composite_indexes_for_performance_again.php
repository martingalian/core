<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds high-value composite indexes for your real schema:
     * - orders:   (position_id,status,created_at), (exchange_symbol_id,status,created_at), (created_at)
     * - indicator_histories: (indicator_id,timeframe,exchange_symbol_id,timestamp)
     * - positions: (status,waped_at,account_id)
     * - fundings: (type,date_value), (type,created_at)
     *
     * NOTE: Names are explicit to make rollback reliable.
     */
    public function up(): void
    {
        // ---- ORDERS ----
        Schema::table('orders', function (Blueprint $table) {
            // Fast lookups by position + status ordered by time
            $table->index(
                ['position_id', 'status', 'created_at'],
                'idx_orders_pos_status_created'
            );

            // Latest orders chronologically
            $table->index(['created_at'], 'idx_orders_created_at');
        });

        // ---- INDICATOR_HISTORIES ----
        Schema::table('indicator_histories', function (Blueprint $table) {
            /**
             * Dashboard “lights” and time-window reads typically filter by:
             *  indicator_id + timeframe + exchange_symbol_id
             * and order by/limit on timestamp.
             *
             * This composite supports WHERE + ORDER BY(timestamp) efficiently.
             */
            $table->index(
                ['indicator_id', 'timeframe', 'exchange_symbol_id', 'timestamp'],
                'idx_indicator_histories_itst'
            );
        });

        // ---- POSITIONS ----
        Schema::table('positions', function (Blueprint $table) {
            /**
             * Your dashboard sorts open positions by most recent waped_at
             * and often scopes by status; include account_id as a tiebreaker/grouper.
             */
            $table->index(
                ['status', 'waped_at', 'account_id'],
                'idx_positions_status_waped_account'
            );
        });

        // ---- FUNDINGS ----
        Schema::table('fundings', function (Blueprint $table) {
            // Monthly deposit-aware computations
            $table->index(['type', 'date_value'], 'idx_funding_type_date');
            $table->index(['type', 'created_at'], 'idx_funding_type_created');
        });
    }
};
