<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            $table->string('canonical')->nullable()->after('workflow_id')
                ->comment('Canonical name for step lookup within a workflow (e.g., set_margin, place_order)');
            $table->index(['workflow_id', 'canonical'], 'steps_workflow_canonical_index');
        });
    }

    public function down(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            $table->dropIndex('steps_workflow_canonical_index');
            $table->dropColumn('canonical');
        });
    }
};
