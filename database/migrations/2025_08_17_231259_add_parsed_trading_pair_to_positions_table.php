<?php

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
        Schema::table('positions', function (Blueprint $table) {
            $table->string('parsed_trading_pair')
                ->nullable()
                ->comment('The parsed trading pair, compatible with the exchange trading pair convention')
                ->after('id');
        });
    }
};
