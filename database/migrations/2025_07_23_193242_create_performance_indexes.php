<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('base_asset_mappers', function (Blueprint $table) {
            $table->index(['api_system_id', 'symbol_token'], 'idx_api_symbol_token');
        });

        Schema::table('steps_dispatcher', function (Blueprint $table) {
            $table->index('current_tick_id', 'idx_current_tick_id');
        });

        // REMOVED: api_systems.canonical already has a unique constraint, which creates an index
        // Schema::table('api_systems', function (Blueprint $table) {
        //     $table->index('canonical', 'idx_canonical');
        // });

        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->index('mark_price_synced_at', 'idx_mark_price_synced_at');
        });
    }
};
