<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Martingalian\Core\Database\Seeders\SchemaSeeder20;
use Martingalian\Core\Models\ApiSystem;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Set taapi_canonical for Bybit API system
        (new SchemaSeeder20)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $bybitApiSystem = ApiSystem::firstWhere('canonical', 'bybit');

        if (! $bybitApiSystem) {
            return;
        }

        $bybitApiSystem->update([
            'taapi_canonical' => null,
        ]);
    }
};
