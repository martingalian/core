<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Exception;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Symbol;

final class CoreSymbolDataSeeder extends Seeder
{
    /**
     * Seed core symbol data (symbols, exchange_symbols, base_asset_mappers).
     *
     * This seeder contains production-ready symbol data to avoid running
     * the 3-hour refresh-core-data discovery process.
     *
     * Dump files are stored alongside this seeder in the Core package.
     */
    public function run(): void
    {
        // Disable observers during seeding to prevent notification spam
        ExchangeSymbol::withoutEvents(function () {
            Symbol::withoutEvents(function () {
                $this->runSeeding();
            });
        });
    }

    /**
     * Run all seeding operations with observers disabled.
     */
    private function runSeeding(): void
    {
        $dumpsPath = __DIR__.'/../dumps';

        $dumps = [
            'symbols' => $dumpsPath.'/symbols.sql',
            'exchange_symbols' => $dumpsPath.'/exchange_symbols.sql',
            'base_asset_mappers' => $dumpsPath.'/base_asset_mappers.sql',
        ];

        // Verify all dump files exist
        foreach ($dumps as $table => $file) {
            if (! File::exists($file)) {
                throw new Exception("Dump file not found for {$table}: {$file}");
            }
        }

        // Execute each dump file in order
        foreach ($dumps as $table => $file) {
            $sql = File::get($file);

            // Remove mysqldump warnings from SQL
            $sql = preg_replace('/^mysqldump:.*$/m', '', $sql);

            // Execute SQL using unprepared statements (faster for bulk inserts)
            DB::unprepared($sql);
        }

        // Set default status for exchange_symbols with CMC IDs (not auto-disabled)
        DB::unprepared('
            UPDATE exchange_symbols es
            INNER JOIN symbols s ON es.symbol_id = s.id
            SET es.auto_disabled = 0,
                es.auto_disabled_reason = NULL,
                es.is_manually_enabled = NULL
            WHERE s.cmc_id IS NOT NULL
        ');
    }
}
