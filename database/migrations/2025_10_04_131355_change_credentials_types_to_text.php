<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Change exchange credential column types to longText
        // Note: CoinMarketCap and Taapi credentials are admin-only and belong
        // in the Martingalian model, not the accounts table
        Schema::table('accounts', function (Blueprint $table) {
            $table->longText('binance_api_key')
                ->nullable()
                ->change();

            $table->longText('binance_api_secret')
                ->nullable()
                ->change();

            $table->longText('bybit_api_key')
                ->nullable()
                ->change();

            $table->longText('bybit_api_secret')
                ->nullable()
                ->change();
        });
    }
};
