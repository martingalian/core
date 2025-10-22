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
            $table->integer('stop_loss_wait_minutes')
                ->default(120)
                ->after('total_limit_orders')
                ->comment('Delay (in minutes) before placing stop-loss');
        });
    }
};
