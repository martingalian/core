<?php

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

            $table->index(['block_uuid', 'type'], 'idx_steps_block_uuid_type');
            $table->index(['state', 'child_block_uuid'], 'idx_steps_state_child_block');
            $table->index('block_uuid', 'idx_steps_block_uuid');
            $table->index('tick_id', 'idx_steps_tick_id');
        });

        Schema::table('base_asset_mappers', function (Blueprint $table) {
            $table->index(['api_system_id', 'symbol_token'], 'idx_bam_system_token');
        });

        Schema::table('exchange_symbols', function (Blueprint $table) {
            if (Schema::hasColumn('exchange_symbols', 'symbol_token')) {
                $table->index('symbol_token', 'idx_exchange_symbols_token');
            }
        });
    }
};
