<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\BaseAssetMapper;
use Martingalian\Core\Models\Symbol;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $bybitApiSystem = ApiSystem::firstWhere('canonical', 'bybit');

        if (! $bybitApiSystem) {
            return;
        }

        // Fetch market data from Bybit to get all symbols with their base assets
        try {
            $response = $bybitApiSystem->apiQueryMarketData();

            foreach ($response->result as $symbolData) {
                $baseAsset = $symbolData['baseAsset'] ?? null;

                if (! $baseAsset) {
                    continue;
                }

                // Check if the base asset starts with a number (e.g., 1000BONK, 10000SATS, 1000000MOG)
                if (! preg_match('/^(\d+)(.+)$/', $baseAsset, $matches)) {
                    continue;
                }

                $prefix = $matches[1]; // e.g., "1000"
                $actualToken = $matches[2]; // e.g., "BONK"

                // Check if the symbol exists in our database
                $symbol = Symbol::firstWhere('token', $actualToken);

                if (! $symbol) {
                    // Skip if we don't have this symbol in our database yet
                    continue;
                }

                // Create the base asset mapper entry
                BaseAssetMapper::updateOrCreate(
                    [
                        'api_system_id' => $bybitApiSystem->id,
                        'symbol_token' => $actualToken,
                    ],
                    [
                        'exchange_token' => $baseAsset,
                    ]
                );
            }
        } catch (Exception $e) {
            // Log error but don't fail the migration
            logger()->error('Failed to create Bybit base asset mappers: '.$e->getMessage());
        }
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

        BaseAssetMapper::where('api_system_id', $bybitApiSystem->id)->delete();
    }
};
