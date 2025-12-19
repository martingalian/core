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
        Schema::table('heartbeats', function (Blueprint $table) {
            $table->text('last_payload')->nullable()->after('metadata')
                ->comment('Raw WebSocket payload from last received message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('heartbeats', function (Blueprint $table) {
            $table->dropColumn('last_payload');
        });
    }
};
