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
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->json('btc_elasticity_long')->nullable()->after('btc_correlation_rolling');
            $table->json('btc_elasticity_short')->nullable()->after('btc_elasticity_long');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->dropColumn([
                'btc_elasticity_long',
                'btc_elasticity_short',
            ]);
        });
    }
};
