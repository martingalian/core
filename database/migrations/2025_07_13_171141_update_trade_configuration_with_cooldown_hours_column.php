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
            $table->unsignedInteger('cooldown_hours')
                ->nullable()
                ->comment('Cooldown hours before an exchange symbols can be selected again')
                ->after('indicator_timeframes');
        });
    }
};
