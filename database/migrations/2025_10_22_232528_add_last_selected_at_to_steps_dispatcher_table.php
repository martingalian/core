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
        Schema::table('steps_dispatcher', function (Blueprint $table) {
            $table->timestamp('last_selected_at', 6)->nullable()->comment('Tracks when this group was last selected for round-robin distribution (microsecond precision)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('steps_dispatcher', function (Blueprint $table) {
            $table->dropColumn('last_selected_at');
        });
    }
};
