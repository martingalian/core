<?php

use Martingalian\Core\Database\Seeders\SchemaSeeder9;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('db:seed', [
            '--class' => SchemaSeeder9::class,
        ]);
    }
};
