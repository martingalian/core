<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Removes unique constraint on symbols.token to allow multiple symbols
     * with the same token but different cmc_id (e.g., two "VELO" tokens).
     */
    public function up(): void
    {
        Schema::table('symbols', function (Blueprint $table) {
            $table->dropUnique('symbols_token_unique');
            $table->index('token', 'symbols_token_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('symbols', function (Blueprint $table) {
            $table->dropIndex('symbols_token_index');
            $table->unique('token', 'symbols_token_unique');
        });
    }
};
