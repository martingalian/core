<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Martingalian\Core\Models\ExchangeSymbol;

final class SchemaSeeder12 extends Seeder
{
    public function run(): void
    {
        ExchangeSymbol::query()->update([
            'limit_quantity_multipliers' => [2, 2, 2.5, 1.5],
        ]);
    }
}
