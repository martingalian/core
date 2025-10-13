<?php

// database/migrations/2025_10_06_000001_add_delivery_columns_to_exchange_symbols_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add Binance delivery (delisting) fields to exchange_symbols.
     *
     * delivery_ts_ms — raw milliseconds since epoch from Binance (nullable).
     * delivery_at    — convenient UTC datetime derived from delivery_ts_ms (nullable).
     */
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_ts_ms')->nullable()->after('min_notional');
            $table->dateTime('delivery_at')->nullable()->after('delivery_ts_ms');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->dropColumn(['delivery_ts_ms', 'delivery_at']);
        });
    }
};
