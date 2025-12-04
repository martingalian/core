<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Martingalian\Core\Database\Seeders\BaseAssetMappersSeeder;
use Martingalian\Core\Database\Seeders\CandlesSeeder;
use Martingalian\Core\Database\Seeders\ExchangeSymbolsSeeder;
use Martingalian\Core\Database\Seeders\SymbolsSeeder;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration seeds the core data tables with production data.
     * Seeders are called in dependency order:
     * 1. symbols (no dependencies)
     * 2. base_asset_mappers (no dependencies)
     * 3. exchange_symbols (depends on symbols via symbol_id)
     * 4. candles (depends on exchange_symbols via exchange_symbol_id)
     */
    public function up(): void
    {
        // Seed symbols (618 records)
        (new SymbolsSeeder)->run();

        // Seed base asset mappers (30 records)
        (new BaseAssetMappersSeeder)->run();

        // Seed exchange symbols (1,182 records)
        (new ExchangeSymbolsSeeder)->run();

        // Set default status for exchange_symbols with CMC IDs (enabled for price updates)
        DB::unprepared('
            UPDATE exchange_symbols es
            INNER JOIN symbols s ON es.symbol_id = s.id
            SET es.auto_disabled = 0,
                es.auto_disabled_reason = NULL,
                es.is_manually_enabled = 1
            WHERE s.cmc_id IS NOT NULL
        ');

        // Seed candles (74,843 records)
        (new CandlesSeeder)->run();
    }

    /**
     * Reverse the migrations.
     *
     * Data is not deleted on rollback to prevent accidental data loss.
     * If you need to clear this data, manually truncate the tables:
     * - candles
     * - exchange_symbols
     * - base_asset_mappers
     * - symbols
     */
    public function down(): void
    {
        // Intentionally left empty - we don't delete production data on rollback
    }
};
