<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class BaseAssetMappersSeeder extends Seeder
{
    public function run(): void
    {
        // Disable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Clear existing data
        DB::table('base_asset_mappers')->truncate();

        // Insert data in batches of 50
        $data = [
            [
                'id' => 1,
                'api_system_id' => 2,
                'symbol_token' => 'BABYDOGE',
                'exchange_token' => '1000000BABYDOGE',
                'created_at' => '2025-11-22 22:07:30',
                'updated_at' => '2025-11-22 22:07:30',
            ],
            [
                'id' => 2,
                'api_system_id' => 2,
                'symbol_token' => 'CHEEMS',
                'exchange_token' => '1000000CHEEMS',
                'created_at' => '2025-11-22 22:08:41',
                'updated_at' => '2025-11-22 22:08:41',
            ],
            [
                'id' => 3,
                'api_system_id' => 2,
                'symbol_token' => 'MOG',
                'exchange_token' => '1000000MOG',
                'created_at' => '2025-11-22 22:09:30',
                'updated_at' => '2025-11-22 22:09:30',
            ],
            [
                'id' => 4,
                'api_system_id' => 2,
                'symbol_token' => 'ELON',
                'exchange_token' => '10000ELON',
                'created_at' => '2025-11-22 22:09:58',
                'updated_at' => '2025-11-22 22:09:58',
            ],
            [
                'id' => 5,
                'api_system_id' => 2,
                'symbol_token' => 'QUBIC',
                'exchange_token' => '10000QUBIC',
                'created_at' => '2025-11-22 22:11:10',
                'updated_at' => '2025-11-22 22:11:10',
            ],
            [
                'id' => 6,
                'api_system_id' => 2,
                'symbol_token' => 'SATS',
                'exchange_token' => '10000SATS',
                'created_at' => '2025-11-22 22:11:53',
                'updated_at' => '2025-11-22 22:11:53',
            ],
            [
                'id' => 7,
                'api_system_id' => 2,
                'symbol_token' => 'BONK',
                'exchange_token' => '1000BONK',
                'created_at' => '2025-11-22 22:12:13',
                'updated_at' => '2025-11-22 22:12:13',
            ],
            [
                'id' => 8,
                'api_system_id' => 2,
                'symbol_token' => 'BTT',
                'exchange_token' => '1000BTT',
                'created_at' => '2025-11-22 22:13:55',
                'updated_at' => '2025-11-22 22:13:55',
            ],
            [
                'id' => 9,
                'api_system_id' => 2,
                'symbol_token' => 'CAT',
                'exchange_token' => '1000CAT',
                'created_at' => '2025-11-22 22:13:56',
                'updated_at' => '2025-11-22 22:13:56',
            ],
            [
                'id' => 10,
                'api_system_id' => 2,
                'symbol_token' => 'FLOKI',
                'exchange_token' => '1000FLOKI',
                'created_at' => '2025-11-22 22:14:41',
                'updated_at' => '2025-11-22 22:14:41',
            ],
            [
                'id' => 11,
                'api_system_id' => 2,
                'symbol_token' => 'LUNC',
                'exchange_token' => '1000LUNC',
                'created_at' => '2025-11-22 22:15:29',
                'updated_at' => '2025-11-22 22:15:29',
            ],
            [
                'id' => 12,
                'api_system_id' => 1,
                'symbol_token' => 'BONK',
                'exchange_token' => '1000BONK',
                'created_at' => '2025-11-22 22:16:09',
                'updated_at' => '2025-11-22 22:16:09',
            ],
            [
                'id' => 13,
                'api_system_id' => 2,
                'symbol_token' => 'PEPE',
                'exchange_token' => '1000PEPE',
                'created_at' => '2025-11-22 22:18:32',
                'updated_at' => '2025-11-22 22:18:32',
            ],
            [
                'id' => 14,
                'api_system_id' => 1,
                'symbol_token' => 'LUNC',
                'exchange_token' => '1000LUNC',
                'created_at' => '2025-11-22 22:18:55',
                'updated_at' => '2025-11-22 22:18:55',
            ],
            [
                'id' => 15,
                'api_system_id' => 2,
                'symbol_token' => 'RATS',
                'exchange_token' => '1000RATS',
                'created_at' => '2025-11-22 22:21:21',
                'updated_at' => '2025-11-22 22:21:21',
            ],
            [
                'id' => 16,
                'api_system_id' => 2,
                'symbol_token' => 'TAG',
                'exchange_token' => '1000TAG',
                'created_at' => '2025-11-22 22:23:11',
                'updated_at' => '2025-11-22 22:23:11',
            ],
            [
                'id' => 17,
                'api_system_id' => 2,
                'symbol_token' => 'TOSHI',
                'exchange_token' => '1000TOSHI',
                'created_at' => '2025-11-22 22:24:30',
                'updated_at' => '2025-11-22 22:24:30',
            ],
            [
                'id' => 18,
                'api_system_id' => 2,
                'symbol_token' => 'TURBO',
                'exchange_token' => '1000TURBO',
                'created_at' => '2025-11-22 22:25:30',
                'updated_at' => '2025-11-22 22:25:30',
            ],
            [
                'id' => 19,
                'api_system_id' => 2,
                'symbol_token' => 'XEC',
                'exchange_token' => '1000XEC',
                'created_at' => '2025-11-22 22:26:56',
                'updated_at' => '2025-11-22 22:26:56',
            ],
            [
                'id' => 20,
                'api_system_id' => 1,
                'symbol_token' => 'BOB',
                'exchange_token' => '1000000BOB',
                'created_at' => '2025-11-22 22:31:51',
                'updated_at' => '2025-11-22 22:31:51',
            ],
            [
                'id' => 21,
                'api_system_id' => 1,
                'symbol_token' => 'CAT',
                'exchange_token' => '1000CAT',
                'created_at' => '2025-11-22 22:32:32',
                'updated_at' => '2025-11-22 22:32:32',
            ],
            [
                'id' => 22,
                'api_system_id' => 1,
                'symbol_token' => 'CHEEMS',
                'exchange_token' => '1000CHEEMS',
                'created_at' => '2025-11-22 22:36:31',
                'updated_at' => '2025-11-22 22:36:31',
            ],
            [
                'id' => 23,
                'api_system_id' => 1,
                'symbol_token' => 'PEPE',
                'exchange_token' => '1000PEPE',
                'created_at' => '2025-11-22 22:49:21',
                'updated_at' => '2025-11-22 22:49:21',
            ],
            [
                'id' => 24,
                'api_system_id' => 1,
                'symbol_token' => 'XEC',
                'exchange_token' => '1000XEC',
                'created_at' => '2025-11-23 00:25:30',
                'updated_at' => '2025-11-23 00:25:30',
            ],
            [
                'id' => 25,
                'api_system_id' => 1,
                'symbol_token' => 'WHY',
                'exchange_token' => '1000WHY',
                'created_at' => '2025-11-23 00:27:15',
                'updated_at' => '2025-11-23 00:27:15',
            ],
            [
                'id' => 26,
                'api_system_id' => 1,
                'symbol_token' => 'FLOKI',
                'exchange_token' => '1000FLOKI',
                'created_at' => '2025-11-23 00:31:36',
                'updated_at' => '2025-11-23 00:31:36',
            ],
            [
                'id' => 27,
                'api_system_id' => 1,
                'symbol_token' => 'MOG',
                'exchange_token' => '1000000MOG',
                'created_at' => '2025-11-23 00:54:06',
                'updated_at' => '2025-11-23 00:54:06',
            ],
            [
                'id' => 28,
                'api_system_id' => 1,
                'symbol_token' => 'RATS',
                'exchange_token' => '1000RATS',
                'created_at' => '2025-11-23 00:54:22',
                'updated_at' => '2025-11-23 00:54:22',
            ],
            [
                'id' => 29,
                'api_system_id' => 1,
                'symbol_token' => 'SHIB',
                'exchange_token' => '1000SHIB',
                'created_at' => '2025-11-23 01:29:10',
                'updated_at' => '2025-11-23 01:29:10',
            ],
            [
                'id' => 30,
                'api_system_id' => 1,
                'symbol_token' => 'SATS',
                'exchange_token' => '1000SATS',
                'created_at' => '2025-11-23 02:05:30',
                'updated_at' => '2025-11-23 02:05:30',
            ],
        ];

        // Insert in batches of 50 to avoid memory issues
        collect($data)->chunk(50)->each(function ($chunk) {
            DB::table('base_asset_mappers')->insert($chunk->toArray());
        });

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        if ($this->command) {
            $this->command->info('Seeded ' . count($data) . ' base asset mappers');
        }
    }
}
