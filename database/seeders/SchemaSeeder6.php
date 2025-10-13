<?php

namespace Martingalian\Core\Database\Seeders;

use App\Models\Symbol;
use Illuminate\Database\Seeder;

class SchemaSeeder6 extends Seeder
{
    public function run(): void
    {
        $ids = [1437, 3155, 7226, 32684, 2011, 6210];

        $rows = [];
        foreach ($ids as $id) {
            $rows[] = ['cmc_id' => $id, 'created_at' => now(), 'updated_at' => now()];
        }

        Symbol::insert($rows);
    }
}
