<?php

namespace Martingalian\Core\Database\Seeders;

use App\Models\Symbol;
use Illuminate\Database\Seeder;

class SchemaSeeder13 extends Seeder
{
    public function run(): void
    {
        $ids = [
            1720, // IOTA
            2566, // ONT
            1684, // QTUM
            1697, // BAT
            1376, // NEO
            2469, // ZIL
            11289, // SPELL
            37566, // DAR
            7501, // WOO
            18876, // APE
            7737, // API3
            18069, // GMT
            4558, // FLOW
            7080, // GALA
        ];

        $rows = [];
        foreach ($ids as $id) {
            $rows[] = ['cmc_id' => $id, 'created_at' => now(), 'updated_at' => now()];
        }

        Symbol::insert($rows);
    }
}
