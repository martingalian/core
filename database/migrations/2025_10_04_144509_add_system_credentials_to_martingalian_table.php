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
        Schema::table('martingalian', function (Blueprint $table) {
            $table->longText('binance_api_key')
                ->nullable()
                ->after('should_kill_order_events');

            $table->longText('binance_api_secret')
                ->nullable()
                ->after('binance_api_key');

            $table->longText('bybit_api_key')
                ->nullable()
                ->after('binance_api_secret');

            $table->longText('bybit_api_secret')
                ->nullable()
                ->after('bybit_api_key');

            $table->longText('coinmarketcap_api_key')
                ->nullable()
                ->after('bybit_api_secret');

            $table->longText('taapi_secret')
                ->nullable()
                ->after('coinmarketcap_api_key');
        });

        Artisan::call('db:seed', [
            '--class' => SchemaSeeder17::class,
        ]);
    }
};
