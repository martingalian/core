<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicator_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exchange_symbol_id')->index();
            $table->unsignedBigInteger('indicator_id')->index();
            $table->string('timeframe')->index();
            $table->string('timestamp')->index();
            $table->json('data');
            $table->text('conclusion')->nullable();
            $table->timestamps();

            // Non-unique composite index for typical lookup patterns.
            $table->index(
                ['exchange_symbol_id', 'indicator_id', 'timeframe', 'timestamp'],
                'idx_indhist_es_i_tf_ts'
            );
        });
    }
};
