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
                'stop_market_initial_percentage',
            ]);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedTinyInteger('stop_market_initial_percentage')
                ->default(10)
                ->after('total_limit_orders_filled_to_notify');
        });
    }
};
