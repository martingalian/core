<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('last_notified_account_balance_history_id');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedTinyInteger('total_limit_orders_filled_to_notify')
                ->default(0)
                ->comment('After how many limit orders should we notify the account user')
                ->after('can_trade');
        });
    }
};
