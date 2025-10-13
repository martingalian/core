<?php

namespace Martingalian\Core\Database\Seeders;

use Martingalian\Core\Models\Symbol;
use Illuminate\Database\Seeder;

class SchemaSeeder15 extends Seeder
{
    public function run(): void
    {
        $ids = [
            11841, // ARB
            21533, // LISTA
            1732, // NMR
            28827, // OMNI
            2539, // REN
            26998, // SCR
            5824, // SLP
            1759, // SNT
            18934, // STG
            6758, // SUSHI
            35892, // TUT
            35421, // VINE
            328, // XMR
            29711, // ZRC
        ];

        $rows = [];
        foreach ($ids as $id) {
            $rows[] = ['cmc_id' => $id, 'created_at' => now(), 'updated_at' => now()];
        }

        Symbol::insert($rows);
    }
}
