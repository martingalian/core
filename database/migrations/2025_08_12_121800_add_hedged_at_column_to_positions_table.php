<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->timestamp('hedged_at')
                ->nullable()
                ->comment('When the position was hedged (last limit order filled)')
                ->after('was_hedged');
        });
    }
};
