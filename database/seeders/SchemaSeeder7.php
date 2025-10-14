<?php

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Martingalian\Core\Models\Symbol;

class SchemaSeeder7 extends Seeder
{
    public function run(): void
    {
        $ids = [
            7129, // USTC
            5864, // YFI
        ];

        $rows = [];
        foreach ($ids as $id) {
            $rows[] = ['cmc_id' => $id, 'created_at' => now(), 'updated_at' => now()];
        }

        Symbol::insert($rows);
    }
}
