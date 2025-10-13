<?php

namespace Martingalian\Core\Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\StepsDispatcher;
use Illuminate\Database\Seeder;

class SchemaSeeder3 extends Seeder
{
    public function run(): void
    {
        StepsDispatcher::create([
            'can_dispatch' => false,
        ]);
    }
}
