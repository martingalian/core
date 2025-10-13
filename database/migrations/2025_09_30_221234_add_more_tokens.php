<?php

use Database\Seeders\SchemaSeeder14;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('db:seed', [
            '--class' => SchemaSeeder14::class,
        ]);
    }
};
