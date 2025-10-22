<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\BaseAssetMapper;

final class SchemaSeeder19 extends Seeder
{
    /**
     * Seed base asset mappers for Bybit.
     * Maps exchange-specific token names (e.g., 1000BONK) to standard token names (e.g., BONK).
     */
    public function run(): void
    {
        $bybitApiSystem = ApiSystem::firstWhere('canonical', 'bybit');

        if (! $bybitApiSystem) {
            if ($this->command) {
                $this->command->warn('Bybit API system not found. Skipping seeder.');
            }

            return;
        }

        $mappers = [
            // Add mappings one by one as discovered
            ['symbol_token' => 'BONK', 'exchange_token' => '1000BONK'],
        ];

        foreach ($mappers as $mapper) {
            BaseAssetMapper::updateOrCreate(
                [
                    'api_system_id' => $bybitApiSystem->id,
                    'symbol_token' => $mapper['symbol_token'],
                ],
                [
                    'exchange_token' => $mapper['exchange_token'],
                ]
            );

            if ($this->command) {
                $this->command->info("Created/Updated mapping: {$mapper['symbol_token']} -> {$mapper['exchange_token']}");
            }
        }
    }
}
