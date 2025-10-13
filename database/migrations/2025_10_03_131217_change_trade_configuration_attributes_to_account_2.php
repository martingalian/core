<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_configuration', function (Blueprint $table) {
            $table->dropColumn([
                'total_positions_long',
                'total_positions_short',
            ]);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedInteger('total_positions_short')
                ->default(1)
                ->comment('Max active positions SHORT')
                ->after('stop_market_initial_percentage');

            $table->unsignedInteger('total_positions_long')
                ->default(1)
                ->comment('Max active positions LONG')
                ->after('total_positions_short');
        });
    }
};
