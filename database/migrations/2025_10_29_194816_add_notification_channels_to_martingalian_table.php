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
        Schema::table('martingalian', function (Blueprint $table) {
            $table->json('notification_channels')->nullable()->after('taapi_secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('martingalian', function (Blueprint $table) {
            $table->dropColumn('notification_channels');
        });
    }
};
