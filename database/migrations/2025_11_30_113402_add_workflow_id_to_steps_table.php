<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add workflow_id column to steps table.
     *
     * This column tracks the entire graph/tree of steps that belong together,
     * enabling simple queries like: SELECT * FROM steps WHERE workflow_id = ?
     * instead of complex recursive CTEs to traverse parent-child relationships.
     */
    public function up(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            $table->uuid('workflow_id')->nullable()->after('tick_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            $table->dropIndex(['workflow_id']);
            $table->dropColumn('workflow_id');
        });
    }
};
