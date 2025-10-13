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
            $table->dropColumn(['was_hedged', 'hedged_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_hedge');
        });

        Schema::table('trade_configuration', function (Blueprint $table) {
            $table->dropColumn([
                'max_margin_ratio_to_close_hedged_positions',
                'hedge_quantity_laddering_percentages',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->boolean('was_hedged')->default(false)->after('was_waped');
            $table->timestamp('hedged_at')->nullable()->after('was_hedged');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_hedge')->default(false)->after('status');
        });

        Schema::table('trade_configuration', function (Blueprint $table) {
            $table->decimal('max_margin_ratio_to_close_hedged_positions', 8, 4)
                ->nullable()
                ->after('some_existing_column'); // ⚠️ adjust placement

            $table->json('hedge_quantity_laddering_percentages')
                ->nullable()
                ->after('max_margin_ratio_to_close_hedged_positions'); // ⚠️ adjust placement
        });
    }
};
