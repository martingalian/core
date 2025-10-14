<?php

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Martingalian\Core\Models\Symbol;

class SchemaSeeder8 extends Seeder
{
    public function run(): void
    {
        $ids = [
            1, // BTC
        ];

        $rows = [];
        foreach ($ids as $id) {
            $rows[] = ['cmc_id' => $id, 'created_at' => now(), 'updated_at' => now()];
        }

        Symbol::insert($rows);
    }
}
