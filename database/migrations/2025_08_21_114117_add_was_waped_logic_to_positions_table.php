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
        Schema::table('positions', function (Blueprint $table) {
            $table->boolean('was_waped')
                ->default(false)
                ->comment('If this position received a WAP recalculation')
                ->after('hedged_at');

            $table->timestamp('waped_at')
                ->nullable()
                ->comment('When was the last time this position was waped')
                ->after('was_waped');
        });
    }
};
