<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Up: add disable_on_price_spike_percentage and drop legacy columns.
     */
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->decimal('disable_on_price_spike_percentage', 4, 2)
                ->default(15) // 15% default
                ->after('limit_quantity_multipliers');

            $table->unsignedTinyInteger('price_spike_cooldown_hours')
                ->default(72)
                ->after('disable_on_price_spike_percentage');

            $table->dropColumn(['levels_hit_total', 'levels_hit_timestamp']);
        });
    }
};
