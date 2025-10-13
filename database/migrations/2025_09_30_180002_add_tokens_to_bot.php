<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\Migrations\Migration;
use Martingalian\Core\Database\Seeders\SchemaSeeder13;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Artisan::call('db:seed', [
            '--class' => SchemaSeeder13::class,
        ]);
    }
};
