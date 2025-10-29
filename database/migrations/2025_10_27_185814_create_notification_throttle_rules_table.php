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
        Schema::create('notification_throttle_rules', function (Blueprint $table) {
            $table->id();
            $table->string('message_canonical')->unique()->comment('Canonical identifier for the notification type');
            $table->unsignedInteger('throttle_seconds')->comment('Minimum seconds between notifications of this type');
            $table->string('description')->nullable()->comment('Human-readable description of this notification type');
            $table->boolean('is_active')->default(true)->comment('Enable/disable this throttle rule without deleting');
            $table->timestamps();

            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_throttle_rules');
    }
};
