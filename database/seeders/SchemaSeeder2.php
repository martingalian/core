<?php

namespace Martingalian\Core\Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\BaseAssetMapper;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Symbol;
use Illuminate\Database\Seeder;

class SchemaSeeder2 extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Disable all current tokens for trading.
        ExchangeSymbol::query()->update(['is_tradeable' => false, 'is_active' => false]);

        // Add only the new high volatility tokens for testing purposes.
        $cmcIds = [
            36920,    // DMC
            36410,    // MYX
            36861,    // NEWT
            12894,    // SQD
            31525,    // TAIKO
            29210,    // JUP
            30171,    // ENA
            20873,    // LEVER
            34466,    // PENGU
            26198,    // BDXN

            7232,     // ALPHA
            33788,    // PNUT
            36922,    // HUSDT
            34034,    // OLUSDT
            7978,     // FIDA
            36775,    // IDOL
            34993,    // SWARMS
            1586,     // ARK
            14783,    // MAGIC
            3978,     // CHR

            19966,    // QUICK
            32325,    // PUFFER
            22461,    // HFT
            35168,    // 1000X
            35430,    // BID
            22861,    // TIA
            36671,    // SAHARA
            14806,    // PEOPLE
            24924,    // SWELL
            28382,    // MYRO

            36713,    // RESOLV
            10974,    // CHESS
            35749,    // BROCCOLI
            34103,    // AIXBT
            36369,    // HAEDAL
            28933,    // XAI
            10688,    // YGG
            30096,    // DEGEN
            28504,    // JOE
            15678,    // VOXEL
        ];

        foreach ($cmcIds as $cmcId) {
            Symbol::create([
                'cmc_id' => $cmcId,
            ]);
        }

        $binance = ApiSystem::firstWhere('canonical', 'binance');

        BaseAssetMapper::create([
            'api_system_id' => $binance->id,
            'symbol_token' => 'BROCCOLI',
            'exchange_token' => 'BROCCOLI714',
        ]);
    }
}
