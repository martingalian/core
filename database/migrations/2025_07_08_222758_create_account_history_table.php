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
        Schema::create('account_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('event_type'); // Usually 'ACCOUNT_UPDATE'
            $table->string('event_reason')->nullable(); // The "m" field
            $table->json('balances')->nullable(); // The "B" field
            $table->json('positions')->nullable(); // The "P" field
            $table->string('transaction_time')->nullable(); // The "T" field
            $table->string('event_time')->nullable(); // The "E" field
            $table->json('raw')->nullable(); // Full raw payload
            $table->timestamps();
        });
    }
};
