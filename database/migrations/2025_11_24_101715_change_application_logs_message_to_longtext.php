<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Change message column from TEXT to LONGTEXT.
     * This allows storing large messages (up to 4GB) such as full API responses.
     */
    public function up(): void
    {
        Schema::table('model_logs', function (Blueprint $table) {
            $table->longText('message')->nullable()->change();
        });
    }

    /**
     * Reverse the migration by changing column back to TEXT.
     */
    public function down(): void
    {
        Schema::table('model_logs', function (Blueprint $table) {
            $table->text('message')->nullable()->change();
        });
    }
};
