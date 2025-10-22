<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

            $table->longText('coinmarketcap_api_key')
                ->nullable()
                ->change();

            $table->longText('taapi_secret')
                ->nullable()
                ->change();
        });
    }
};
