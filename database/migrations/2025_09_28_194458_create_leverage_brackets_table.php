<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leverage_brackets', function (Blueprint $table) {
            $table->id();

            // Relation to exchange_symbols
            $table->foreignId('exchange_symbol_id');

            // Bracket attributes
            $table->unsignedSmallInteger('bracket');
            $table->unsignedSmallInteger('initial_leverage');
            $table->decimal('notional_floor', 30, 8);
            $table->decimal('notional_cap', 30, 8);
            $table->decimal('maint_margin_ratio', 18, 10);
            $table->unsignedBigInteger('cum')->nullable();

            // Raw source payload (auditing/debugging)
            $table->json('source_payload')->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // Ensure uniqueness per exchange symbol + bracket
            $table->unique(['exchange_symbol_id', 'bracket'], 'uniq_symbol_bracket');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leverage_brackets');
    }
};
