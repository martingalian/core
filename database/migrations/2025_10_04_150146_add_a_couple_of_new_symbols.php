<?php

use Illuminate\Database\Migrations\Migration;
use Martingalian\Core\Database\Seeders\SchemaSeeder18;

return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('db:seed', [
            '--class' => SchemaSeeder18::class,
        ]);
    }
};
