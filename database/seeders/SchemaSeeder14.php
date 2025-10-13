<?php

namespace Martingalian\Core\Database\Seeders;

use App\Models\Symbol;
use Illuminate\Database\Seeder;

class SchemaSeeder14 extends Seeder
{
    public function run(): void
    {
        $ids = [
            6958, // ACH
            29270, // AERO
            29676, // AEVO
            8766, // ALICE
            6783, // AXS
            10903, // C98
            4066, // CHZ
            6538, // CRV
            131, // DASH
            4092, // DUSK
            28324, // DYDX
            6892, // EGLD
            2130, // ENJ
            3773, // FET
            3513, // FTM
            4195, // FTT
            11857, // GMX
            10603, // IMX
            8425, // JASMY
            4846, // KAVA
            3640, // LPT
            1966, // MANA
            8536, // MASK
            8646, // MINA
            6536, // OM
            9481, // PENDLE
            8526, // RAY
            30843, // REZ
            7653, // ROSE
            4157, // RUNE
            8119, // SFP
            2586, // SNX
            28081, // SPX
            4847, // STX
            36405, // SXT
            2416, // THETA
            7725, // TRU
            7288, // XVS
            1698, // ZEN
            1896, // ZRX
        ];

        $rows = [];
        foreach ($ids as $id) {
            $rows[] = ['cmc_id' => $id, 'created_at' => now(), 'updated_at' => now()];
        }

        Symbol::insert($rows);
    }
}
