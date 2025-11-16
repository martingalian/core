<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Martingalian\Core\Database\Seeders\CoreSymbolDataSeeder;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Seed core symbol data (symbols, exchange_symbols, base_asset_mappers)
        $seeder = new CoreSymbolDataSeeder;
        $seeder->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data seeding migrations are not reversible
        // Use refresh-core-data command to regenerate symbol data
    }
};
