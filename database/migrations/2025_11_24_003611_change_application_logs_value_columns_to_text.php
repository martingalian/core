<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Change previous_value and new_value columns from JSON to LONGTEXT.
     * This allows storing raw database values (integers, strings, etc.) without JSON encoding.
     * LONGTEXT supports up to 4GB of data, necessary for large array values.
     */
    public function up(): void
    {
        // For MySQL, we need to alter the column type from JSON to LONGTEXT
        Schema::table('application_logs', function (Blueprint $table) {
            $table->longText('previous_value')->nullable()->change();
            $table->longText('new_value')->nullable()->change();
        });
    }

    /**
     * Reverse the migration by changing columns back to JSON.
     */
    public function down(): void
    {
        Schema::table('application_logs', function (Blueprint $table) {
            $table->json('previous_value')->nullable()->change();
            $table->json('new_value')->nullable()->change();
        });
    }
};
