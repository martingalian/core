<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->decimal('limit_quantity_multiplier', 8, 4)->unsigned()->default(2.0000)->after('indicators_values');
        });
    }
};
