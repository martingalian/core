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
        Schema::table('symbols', function (Blueprint $table) {
            $table->unsignedInteger('cmc_ranking')->nullable()->after('cmc_id');
            $table->boolean('is_stable_coin')->default(false)->after('cmc_ranking');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('symbols', function (Blueprint $table) {
            $table->dropColumn(['cmc_ranking', 'is_stable_coin']);
        });
    }
};
