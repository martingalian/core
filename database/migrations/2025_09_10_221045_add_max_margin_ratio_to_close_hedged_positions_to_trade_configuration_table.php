<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_configuration', function (Blueprint $table) {
            $table->decimal('max_margin_ratio_to_close_hedged_positions', 5, 2)
                ->default(50)
                ->comment('Maximum allowed margin ratio to close the next hedged position')
                ->after('total_limit_orders');
        });
    }
};
