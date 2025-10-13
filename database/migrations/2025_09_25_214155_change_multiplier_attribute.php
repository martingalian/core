<?php

use Martingalian\Core\Database\Seeders\SchemaSeeder12;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            // Rename the old scalar column to the new JSON array column
            $table->renameColumn('limit_quantity_multiplier', 'limit_quantity_multipliers');
        });

        Schema::table('exchange_symbols', function (Blueprint $table) {
            // Change column type to JSON with a default
            $table->json('limit_quantity_multipliers')
                ->nullable()
                ->change();
        });

        Artisan::call('db:seed', [
            '--class' => SchemaSeeder12::class,
        ]);
    }
};
