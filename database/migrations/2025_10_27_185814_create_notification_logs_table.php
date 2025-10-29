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
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->comment('Admin user who received the notification');
            $table->string('message_canonical')->comment('Canonical identifier for the notification type');
            $table->timestamp('last_sent_at')->comment('When this notification was last sent to this user');
            $table->timestamps();

            $table->unique(['user_id', 'message_canonical']);
            $table->index('last_sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
