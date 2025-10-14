<?php

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Martingalian\Core\Models\Indicator;

class SchemaSeeder11 extends Seeder
{
    public function run(): void
    {
        Indicator::create([
            'canonical' => 'price-volatility',
            'is_active' => true,
            'type' => 'reports',
            'class' => "App\Indicators\Reports\PriceVolatilityIndicator",
            'is_apiable' => true,
            'parameters' => ['results' => 2000],
        ]);

        Indicator::where('canonical', 'candle')->where('type', 'history')->first()->update(['type' => 'dashboard']);
    }
}
