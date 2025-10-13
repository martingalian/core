<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trade_configuration', function (Blueprint $table) {
            $table->dropColumn([
                'total_limit_orders',
            ]);
        });

        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->unsignedInteger('total_limit_orders')
                ->default(4)
                ->comment('Total limit orders, for the martingale calculation')
                ->after('symbol_information');
        });
    }
};
