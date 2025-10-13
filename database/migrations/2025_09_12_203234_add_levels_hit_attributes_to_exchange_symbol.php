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
            // Add total hits as integer (nullable for fresh/uncomputed symbols)
            $table->unsignedBigInteger('levels_hit_total')
                ->nullable()
                ->after('indicators_values');

            // Add timestamp of when the total hits were computed
            $table->timestamp('levels_hit_timestamp')
                ->nullable()
                ->after('levels_hit_total');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->dropColumn(['levels_hit_total', 'levels_hit_timestamp']);
        });
    }
};
