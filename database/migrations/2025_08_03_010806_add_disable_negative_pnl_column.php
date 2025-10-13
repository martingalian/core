<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_configuration', function (Blueprint $table) {
            $table->boolean('disable_exchange_symbol_from_negative_pnl_position')
                ->default(false)
                ->comment('If a position is closed with a negative PnL, then the exchange symbol is immediately disabled for trading')
                ->after('total_limit_orders');
        });
    }
};
