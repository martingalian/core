<?php

use Database\Seeders\SchemaSeeder11;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('db:seed', [
            '--class' => SchemaSeeder11::class,
        ]);
    }
};
