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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('canonical')->unique()->comment('Base message canonical identifier (e.g., ip_not_whitelisted)');
            $table->string('title')->comment('Human-readable notification title');
            $table->text('description')->nullable()->comment('Description of when this notification is sent');
            $table->string('default_severity')->nullable()->comment('Default severity level (Critical, High, Medium, Info)');
            $table->boolean('is_active')->default(true)->comment('Whether this notification can be sent');
            $table->timestamps();

            $table->index('canonical');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
