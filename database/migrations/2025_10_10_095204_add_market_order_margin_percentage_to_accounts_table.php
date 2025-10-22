<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'position_margin_percentage_short',
                'position_margin_percentage_long',
            ]);

            $table->decimal('market_order_margin_percentage_long', 5, 2)
                ->default(0.42) // 0.42% more or less 5.5% of total position.
                ->after('can_trade');

            $table->decimal('market_order_margin_percentage_short', 5, 2)
                ->default(0.37) // 0.37% more or less 4.5% of total position.
                ->after('market_order_margin_percentage_long');
        });
    }
};
