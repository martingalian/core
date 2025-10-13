<?php

use Database\Seeders\SchemaSeeder5;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('martingalian', function (Blueprint $table) {
            $table->id();

            $table->boolean('should_kill_order_events')
                ->default(false);

            $table->timestamps();
        });

        Artisan::call('db:seed', [
            '--class' => SchemaSeeder5::class,
        ]);
    }
};
