<?php

use Illuminate\Database\Migrations\Migration;
use Martingalian\Core\Database\Seeders\SchemaSeeder15;

return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('db:seed', [
            '--class' => SchemaSeeder15::class,
        ]);
    }
};
