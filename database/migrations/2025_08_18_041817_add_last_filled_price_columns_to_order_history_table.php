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
        Schema::table('order_history', function (Blueprint $table) {
            $table->string('lastFilledPrice')
                ->nullable()
                ->after('cumQuote');

            $table->string('lastFilledQty')
                ->nullable()
                ->after('lastFilledPrice');
        });
    }
};
