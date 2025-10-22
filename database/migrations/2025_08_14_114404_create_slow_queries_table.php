<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slow_queries', function (Blueprint $table) {
            $table->id();
            $table->string('tick_id')->nullable()->index();
            $table->string('connection', 64)->index();
            $table->unsignedInteger('time_ms')->index();
            $table->mediumText('sql');
            $table->mediumText('sql_full')->nullable();
            $table->json('bindings')->nullable();
            $table->timestamps();
            $table->index(['created_at', 'time_ms']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slow_queries');
    }
};
