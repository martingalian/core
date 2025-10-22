<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            if (! Schema::hasColumn('steps', 'tick_id')) {
                $table->string('tick_id')->nullable()->after('id');
            }

            // Removed redundant indexes (already in main schema or other migrations):
            // - ['block_uuid', 'type'] -> already in main schema
            // - 'block_uuid' -> already in main schema
            // - ['state', 'child_block_uuid'] -> superseded by idx_steps_parent_lookup

            $table->index('tick_id', 'idx_steps_tick_id');
        });

        // Removed redundant index (already in 2025_07_23_193242)
        // Schema::table('base_asset_mappers'...

        Schema::table('exchange_symbols', function (Blueprint $table) {
            if (Schema::hasColumn('exchange_symbols', 'symbol_token')) {
                $table->index('symbol_token', 'idx_exchange_symbols_token');
            }
        });
    }
};
