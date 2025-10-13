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
        Schema::table('application_logs', function (Blueprint $table) {
            $table->index('created_at', 'idx_application_logs_created_at');
        });

        Schema::table('api_request_logs', function (Blueprint $table) {
            $table->index('created_at', 'idx_api_request_logs_created_at');
        });

        Schema::table('steps', function (Blueprint $table) {
            $table->index('created_at', 'idx_steps_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
