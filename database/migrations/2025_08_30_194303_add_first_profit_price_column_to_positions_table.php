<?php

use Martingalian\Core\Database\Seeders\SchemaSeeder10;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('first_profit_price', 20, 8)
                ->nullable()
                ->comment('The first profit price, to be used for the alpha path calculation')
                ->after('quantity');
        });

        Artisan::call('db:seed', [
            '--class' => SchemaSeeder10::class,
        ]);
    }
};
