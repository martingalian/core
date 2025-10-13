<?php

namespace Martingalian\Core\Database\Seeders;

use Martingalian\Core\Models\Indicator;
use Illuminate\Database\Seeder;

class SchemaSeeder9 extends Seeder
{
    public function run(): void
    {
        Indicator::query()->where('canonical', 'candle-comparison')
            ->update(['class' => 'App\Indicators\RefreshData\CandleComparisonIndicator']);

        Indicator::create([
            'type' => 'history',
            'is_active' => true,
            'is_apiable' => true,
            'canonical' => 'candle',
            'parameters' => ['results' => 1],
            'class' => "App\Indicators\History\CandleIndicator",
        ]);
    }
}
