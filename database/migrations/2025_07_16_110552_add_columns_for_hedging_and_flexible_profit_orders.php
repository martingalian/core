<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Martingalian\Core\Database\Seeders\SchemaSeeder4;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_configuration', function (Blueprint $table) {
            $table->json('hedge_quantity_laddering_percentages')
                ->nullable()
                  // ->default([110, 75, 40, 20])
                ->comment('Hedge quantity percentages given the total active positions that are already on hedging')
                ->after('position_margin_percentage_short');
        });

        Artisan::call('db:seed', [
            '--class' => SchemaSeeder4::class,
        ]);
    }
};
