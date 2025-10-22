<?php

declare(strict_types=1);

// database/migrations/2025_09_23_010000_add_candle_time_to_candles_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candles', function (Blueprint $table) {
            // Add a real datetime version of the candle timestamp
            $table->dateTime('candle_time')
                ->nullable()
                ->after('timestamp')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('candles', function (Blueprint $table) {
            $table->dropIndex(['candle_time']);
            $table->dropColumn('candle_time');
        });
    }
};
