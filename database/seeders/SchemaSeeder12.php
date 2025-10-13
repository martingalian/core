<?php

namespace Martingalian\Core\Database\Seeders;

use App\Models\ExchangeSymbol;
use Illuminate\Database\Seeder;

class SchemaSeeder12 extends Seeder
{
    public function run(): void
    {
        ExchangeSymbol::query()->update([
            'limit_quantity_multipliers' => [2, 2, 2.5, 1.5],
        ]);
    }
}
