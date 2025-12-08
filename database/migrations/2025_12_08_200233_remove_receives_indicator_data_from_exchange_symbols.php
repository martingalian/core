<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Remove unused receives_indicator_data column - has_taapi_data serves the same purpose.
     */
    public function up(): void
    {
        // Check if column exists before trying to drop it
        if (Schema::hasColumn('exchange_symbols', 'receives_indicator_data')) {
            // Check if index exists before dropping
            $indexExists = DB::select("SHOW INDEX FROM exchange_symbols WHERE Key_name = 'idx_exchange_symbols_receives_indicator_data'");

            Schema::table('exchange_symbols', function (Blueprint $table) use ($indexExists) {
                if ($indexExists) {
                    $table->dropIndex('idx_exchange_symbols_receives_indicator_data');
                }
                $table->dropColumn('receives_indicator_data');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('exchange_symbols', 'receives_indicator_data')) {
            Schema::table('exchange_symbols', function (Blueprint $table) {
                $table->boolean('receives_indicator_data')->default(true)->after('has_taapi_data');
                $table->index('receives_indicator_data', 'idx_exchange_symbols_receives_indicator_data');
            });
        }
    }
};
