<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * This migration:
     * - Adds composite indexes used by your controllers/traits.
     * - Uses prefix lengths on VARCHAR columns in `orders` to stay under MySQL's 3072-byte key limit.
     *
     * Notes:
     * - Prefix lengths chosen are conservative and fully cover your actual values:
     *     position_side(8)   → 'LONG'/'SHORT'
     *     type(16)           → 'LIMIT','MARKET','STOP-MARKET','PROFIT-LIMIT','PROFIT-MARKET'
     *     status(16)         → 'NEW','FILLED','PARTIALLY_FILLED','CANCELLED','EXPIRED'
     *     exchange_order_id(64) → plenty for common exchange ids
     */
    public function up(): void
    {
        // === account_balance_history (singular) ===
        Schema::table('account_balance_history', function (Blueprint $table) {
            // WHERE account_id = ? AND created_at BETWEEN ? AND ?
            // WHERE account_id = ? AND created_at < ?
            $table->index(['account_id', 'created_at'], 'ab_hist_account_created_idx');
        });

        // === positions ===
        Schema::table('positions', function (Blueprint $table) {
            // WHERE account_id = ? AND closed_at BETWEEN ? AND ?
            $table->index(['account_id', 'closed_at'], 'pos_account_closed_idx');
            // Optional single-column for queries that omit account_id
            $table->index(['closed_at'], 'pos_closed_idx');
        });

        // === orders ===
        // We use raw SQL to specify prefix lengths on composite indexes (Blueprint has no per-column length API).
        // The chosen prefixes keep the index tiny while fully selective for your data.
        // Make sure your table/columns below match these names/types in your schema.

        // (position_id, position_side(8), type(16), id)  → nextPendingLimitOrderPrice(), profitOrder(), marketOrder(), etc.
        DB::statement('
            ALTER TABLE `orders`
            ADD INDEX `ord_pos_side_type_id_idx`
            (`position_id`, `position_side`(8), `type`(16), `id`)
        ');

        // (position_id, position_side(8), status(16), id) → totalLimitOrdersFilled(), last FILLED lookups
        DB::statement('
            ALTER TABLE `orders`
            ADD INDEX `ord_pos_side_status_id_idx`
            (`position_id`, `position_side`(8), `status`(16), `id`)
        ');

        // (position_id, position_side(8), type(16), status(16), quantity) → lastLimitOrder() sorting by quantity
        // (we omit exchange_order_id here to keep the key lean; see separate index below)
        DB::statement('
            ALTER TABLE `orders`
            ADD INDEX `ord_limit_qty_idx`
            (`position_id`, `position_side`(8), `type`(16), `status`(16), `quantity`)
        ');

        // Single-column for fast exact lookups / joins on exchange_order_id (prefix 64 is plenty)
        DB::statement('
            ALTER TABLE `orders`
            ADD INDEX `ord_exchange_order_id_idx`
            (`exchange_order_id`(64))
        ');

        // IMPORTANT: We intentionally DO NOT create the previously-failing wide index
        // `ord_pos_side_type_status_id_idx` (5-part, 3×VARCHAR(255)) because it exceeds key length on utf8mb4.
        // The three indexes above cover your query patterns without hitting 3072 bytes.
    }

    public function down(): void
    {
        // Rollback in reverse order

        // orders
        try {
            DB::statement('ALTER TABLE `orders` DROP INDEX `ord_pos_side_type_id_idx`');
        } catch (\Throwable $e) {
        }
        try {
            DB::statement('ALTER TABLE `orders` DROP INDEX `ord_pos_side_status_id_idx`');
        } catch (\Throwable $e) {
        }
        try {
            DB::statement('ALTER TABLE `orders` DROP INDEX `ord_limit_qty_idx`');
        } catch (\Throwable $e) {
        }
        try {
            DB::statement('ALTER TABLE `orders` DROP INDEX `ord_exchange_order_id_idx`');
        } catch (\Throwable $e) {
        }

        // positions
        Schema::table('positions', function (Blueprint $table) {
            $table->dropIndex('pos_account_closed_idx');
            $table->dropIndex('pos_closed_idx');
        });

        // account_balance_history (singular)
        Schema::table('account_balance_history', function (Blueprint $table) {
            $table->dropIndex('ab_hist_account_created_idx');
        });
    }
};
