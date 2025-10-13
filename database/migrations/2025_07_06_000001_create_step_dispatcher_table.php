<?php

use Martingalian\Core\Database\Seeders\SchemaSeeder3;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('steps_dispatcher', function (Blueprint $table) {
            $table->id();

            $table->boolean('can_dispatch')
                ->default(false)
                ->comment('Flag that allows the dispatch steps to happen, to avoid concurrency issues');

            $table->timestamps();
        });

        Artisan::call('db:seed', [
            '--class' => SchemaSeeder3::class,
        ]);
    }
};
