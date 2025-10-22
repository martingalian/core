<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Martingalian\Core\Database\Seeders\SchemaSeeder2;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->boolean('is_tradeable')
                ->default(false)
                ->after('is_active');
        });

        Artisan::call('db:seed', [
            '--class' => SchemaSeeder2::class,
        ]);
    }
};
