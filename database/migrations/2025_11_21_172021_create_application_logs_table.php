<?php

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
        Schema::create('application_logs', function (Blueprint $table) {
            $table->id();

            // SOURCE MODEL: The model being changed (automatic)
            $table->morphs('loggable'); // loggable_type, loggable_id

            // RELATABLE MODEL: The model that triggered this change (manual/optional)
            $table->nullableMorphs('relatable'); // relatable_type, relatable_id

            // Event information
            $table->string('event_type'); // 'attribute_created', 'attribute_changed', 'job_failed', etc.
            $table->string('attribute_name')->nullable(); // For attribute changes

            // Human-readable message
            $table->text('message')->nullable(); // "Attribute 'status' changed from 'pending' to 'completed'"

            // Values (stored as JSON for flexibility)
            $table->json('previous_value')->nullable(); // The old value
            $table->json('new_value')->nullable();      // The new value

            // Metadata for custom manual logs
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['loggable_type', 'loggable_id']);
            $table->index(['relatable_type', 'relatable_id']);
            $table->index('event_type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_logs');
    }
};
