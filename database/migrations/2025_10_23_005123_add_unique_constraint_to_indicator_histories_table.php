<?php

declare(strict_types=1);

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
        Schema::table('indicator_histories', function (Blueprint $table) {
            $table->unique(
                ['exchange_symbol_id', 'indicator_id', 'timeframe', 'timestamp'],
                'idx_unique_indicator_history'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('indicator_histories', function (Blueprint $table) {
            $table->dropUnique('idx_unique_indicator_history');
        });
    }
};
