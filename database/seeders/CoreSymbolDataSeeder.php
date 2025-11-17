<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

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
        $dumpsPath = __DIR__ . '/../dumps';

        $dumps = [
            'symbols' => $dumpsPath . '/symbols.sql',
            'exchange_symbols' => $dumpsPath . '/exchange_symbols.sql',
            'base_asset_mappers' => $dumpsPath . '/base_asset_mappers.sql',
        ];

        // Verify all dump files exist
        foreach ($dumps as $table => $file) {
            if (! File::exists($file)) {
                throw new \Exception("Dump file not found for {$table}: {$file}");
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

        // Activate exchange_symbols for symbols that have CMC IDs
        DB::unprepared('
            UPDATE exchange_symbols es
            INNER JOIN symbols s ON es.symbol_id = s.id
            SET es.is_active = 1
            WHERE s.cmc_id IS NOT NULL
        ');
    }
}
