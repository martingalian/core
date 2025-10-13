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
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->decimal('min_price', 20, 8)
                ->nullable()
                ->comment('Min price for this exchange symbol')
                ->after('tick_size');
        });
    }
};
