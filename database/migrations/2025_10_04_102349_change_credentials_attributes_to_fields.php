<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Martingalian\Core\Database\Seeders\SchemaSeeder16;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            /**
             * Add real specific fields for each of the api system credentials,
             * on this case just for exchanges.
             */
            $table->longText('binance_api_key')
                ->nullable()
                ->after('credentials');

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

            $table->dropColumn([
                'credentials_testing',
            ]);
        });

        Artisan::call('db:seed', [
            '--class' => SchemaSeeder16::class,
        ]);

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'credentials',
            ]);
        });
    }
};
