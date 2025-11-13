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
            $table->decimal('btc_correlation_pearson', 5, 4)->nullable()->after('indicators_synced_at');
            $table->decimal('btc_correlation_spearman', 5, 4)->nullable()->after('btc_correlation_pearson');
            $table->decimal('btc_correlation_rolling', 5, 4)->nullable()->after('btc_correlation_spearman');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->dropColumn([
                'btc_correlation_pearson',
                'btc_correlation_spearman',
                'btc_correlation_rolling',
            ]);
        });
    }
};
