<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('repeaters', function (Blueprint $table) {
            $table->id();
            $table->string('class');
            $table->json('parameters')->nullable();
            $table->string('queue')->default('repeaters');
            $table->timestamp('next_run_at')->index();
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(10);
            $table->timestamp('last_run_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('status')->default('pending');

            $table->string('retry_strategy')->default('exponential');
            $table->integer('retry_interval_minutes')->default(5);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repeaters');
    }
};
