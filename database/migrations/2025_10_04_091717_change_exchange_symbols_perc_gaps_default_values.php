<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->decimal('percentage_gap_long', 5, 2)->default(8.50)->change();
            $table->decimal('percentage_gap_short', 5, 2)->default(9.50)->change();

            $table->json('limit_quantity_multipliers')
                ->default(DB::raw('(JSON_ARRAY(2,2,2.5,2.5))'))
                ->change();
        });
    }
};
