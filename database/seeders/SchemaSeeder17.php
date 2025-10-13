<?php

namespace Martingalian\Core\Database\Seeders;

use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Martingalian;
use Illuminate\Database\Seeder;

class SchemaSeeder17 extends Seeder
{
    public function run(): void
    {
        // Admin account
        $martingalian = Martingalian::find(1);

        $martingalian->binance_api_key = env('BINANCE_API_KEY');
        $martingalian->binance_api_secret = env('BINANCE_API_SECRET');
        $martingalian->coinmarketcap_api_key = env('COINMARKETCAP_API_KEY');
        $martingalian->taapi_secret = env('TAAPI_SECRET');
        $martingalian->save();
    }
}
