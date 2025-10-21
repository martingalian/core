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
        Schema::table('martingalian', function (Blueprint $table) {
            $table->boolean('allow_opening_positions')
                ->default(false)
                ->after('taapi_secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('martingalian', function (Blueprint $table) {
            $table->dropColumn('allow_opening_positions');
        });
    }
};
