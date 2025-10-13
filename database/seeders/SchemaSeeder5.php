<?php

namespace Martingalian\Core\Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Martingalian;
use Illuminate\Database\Seeder;

class SchemaSeeder5 extends Seeder
{
    public function run(): void
    {
        Martingalian::create([
            'should_kill_order_events' => false,
        ]);
    }
}
