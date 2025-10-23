<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Martingalian\Core\Models\Account;

final class SchemaSeeder16 extends Seeder
{
    public function run(): void
    {
        // Admin account - migrate exchange credentials only
        // Note: CoinMarketCap and Taapi credentials are admin-only and belong
        // in the Martingalian model (seeded in SchemaSeeder17)
        $account = Account::find(1);

        $account->binance_api_key = $account->credentials['api_key'];
        $account->binance_api_secret = $account->credentials['api_secret'];
        $account->save();
    }
}
