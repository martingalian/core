<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Martingalian\Core\Models\Indicator;

final class SchemaSeeder9 extends Seeder
{
    public function run(): void
    {
        Indicator::query()->where('canonical', 'candle-comparison')
            ->update(['class' => 'Martingalian\Core\Indicators\RefreshData\CandleComparisonIndicator']);

        Indicator::create([
            'type' => 'history',
            'is_active' => true,
            'is_apiable' => true,
            'canonical' => 'candle',
            'parameters' => ['results' => 1],
            'class' => "Martingalian\Core\Indicators\History\CandleIndicator",
        ]);
    }
}
