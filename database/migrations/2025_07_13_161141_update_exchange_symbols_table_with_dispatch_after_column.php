<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->timestamp('tradeable_at')
                ->nullable()
                ->comment('Cooldown timestamp so a symbol cannot be tradeable until a certain moment')
                ->after('mark_price_synced_at');
        });
    }
};
