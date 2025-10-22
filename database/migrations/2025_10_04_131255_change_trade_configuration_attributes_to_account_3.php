<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_configuration', function (Blueprint $table) {
            $table->dropColumn([
                'position_margin_percentage_short',
                'position_margin_percentage_long',
                'profit_percentage',
                'minimum_balance',
                'stop_loss_wait_minutes',
                'position_leverage_long',
                'position_leverage_short',
                'cooldown_hours',
            ]);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('profit_percentage', 6, 3)
                ->default(0.360)
                ->comment('The profit percentage')
                ->after('can_trade');

            $table->decimal('position_margin_percentage_short', 5, 2)
                ->default(9.5)
                ->comment('The margin percentage that will be used on each SHORT position')
                ->after('profit_percentage');

            $table->decimal('position_margin_percentage_long', 5, 2)
                ->default(8.5)
                ->comment('The margin percentage that will be used on each LONG position')
                ->after('position_margin_percentage_short');

            $table->integer('stop_market_wait_minutes')
                ->default(120)
                ->after('total_positions_long')
                ->comment('Delay (in minutes) before placing market stop-loss');

            $table->unsignedInteger('position_leverage_short')
                ->default(15)
                ->comment('The max leverage that the position SHORT can use')
                ->after('stop_market_wait_minutes');

            $table->unsignedInteger('position_leverage_long')
                ->default(20)
                ->comment('The max leverage that the position LONG can use')
                ->after('position_leverage_short');
        });
    }
};
