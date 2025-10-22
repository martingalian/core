<?php

declare(strict_types=1);

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
        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('margin_ratio_threshold_to_notify', 5, 2)
                ->default(1.50)
                ->comment('Minimum margin ratio to start notifying the account admin')
                ->after('can_trade');
        });
    }
};
