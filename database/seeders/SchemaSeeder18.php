<?php

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Martingalian\Core\Models\Symbol;

class SchemaSeeder18 extends Seeder
{
    public function run(): void
    {
        $ids = [
            9329,  // CELO
            1934,  // LRC
            3217,  // ONG
            11294, // RARE
            30372, // SAGA
        ];

        $rows = [];
        foreach ($ids as $id) {
            $rows[] = ['cmc_id' => $id, 'created_at' => now(), 'updated_at' => now()];
        }

        Symbol::insert($rows);
    }
}
