<?php

namespace Martingalian\Core\Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Martingalian\Core\Models\StepsDispatcher;

class SchemaSeeder3 extends Seeder
{
    public function run(): void
    {
        StepsDispatcher::create([
            'can_dispatch' => false,
        ]);
    }
}
