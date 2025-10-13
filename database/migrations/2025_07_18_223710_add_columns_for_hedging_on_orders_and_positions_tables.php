<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->boolean('was_hedged')
                ->default(false)
                ->comment('If the position was hedged, meaning an hedge position was opened after 4th limit order filled')
                ->after('closed_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_hedge')
                ->default(false)
                ->comment('Order is an hedge-related position order')
                ->after('position_side');
        });
    }
};
