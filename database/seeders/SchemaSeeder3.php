<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Martingalian\Core\Models\StepsDispatcher;

final class SchemaSeeder3 extends Seeder
{
    public function run(): void
    {
        StepsDispatcher::create([
            'can_dispatch' => true,
        ]);
    }
}
