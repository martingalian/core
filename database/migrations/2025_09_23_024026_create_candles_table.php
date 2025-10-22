<?php

declare(strict_types=1);

// database/migrations/2025_09_23_000000_create_candles_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candles', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('exchange_symbol_id');

            // Add timeframe explicitly (needed for multiple granularities)
            $table->string('timeframe', 16);

            $table->decimal('open', 36, 18);
            $table->decimal('high', 36, 18);
            $table->decimal('low', 36, 18);
            $table->decimal('close', 36, 18);
            $table->decimal('volume', 36, 18)->default('0');

            // Epoch timestamp (seconds or ms)
            $table->unsignedBigInteger('timestamp');

            $table->timestamps();

            // Foreign key (no cascade or restrict on delete)
            $table->foreign('exchange_symbol_id')
                ->references('id')
                ->on('exchange_symbols');

            // Unique constraint — prevents duplicates per (symbol, timeframe, timestamp)
            // NOTE: Unique constraints automatically create an index, so no separate index needed
            $table->unique(
                ['exchange_symbol_id', 'timeframe', 'timestamp'],
                'candles_symbol_timeframe_timestamp_unique'
            );

            // Single-column index for timestamp-only queries
            $table->index('timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candles');
    }
};
