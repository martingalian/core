<?php

namespace Martingalian\Core\Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Martingalian\Core\Models\TradeConfiguration;
use Illuminate\Database\Seeder;

class SchemaSeeder4 extends Seeder
{
    public function run(): void
    {
        TradeConfiguration::query()->update([
            'hedge_quantity_laddering_percentages' => [110, 75, 40, 20],
        ]);
    }
}
