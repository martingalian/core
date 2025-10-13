<?php

namespace Martingalian\Core\Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class SchemaSeeder16 extends Seeder
{
    public function run(): void
    {
        // Admin account
        $account = Account::find(1);

        $account->binance_api_key = $account->credentials['api_key'];
        $account->binance_api_secret = $account->credentials['api_secret'];
        $account->coinmarketcap_api_key = config('martingalian.api.credentials.coinmarketcap.api_key');
        $account->taapi_secret = config('martingalian.api.credentials.taapi.secret');
        $account->save();
    }
}
