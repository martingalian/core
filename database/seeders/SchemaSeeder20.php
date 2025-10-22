<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Martingalian\Core\Models\ApiSystem;

final class SchemaSeeder20 extends Seeder
{
    /**
     * Set taapi_canonical for Bybit API system.
     * This is required for the FetchAndStoreOnCandleJob to pass the correct Taapi exchange identifier.
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

        $bybitApiSystem->update([
            'taapi_canonical' => 'bybit',
        ]);

        if ($this->command) {
            $this->command->info("Set taapi_canonical='bybit' for Bybit API system.");
        }
    }
}
