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
        Schema::table('notifications', function (Blueprint $table) {
            $table->text('usage_reference')->nullable()->after('detailed_description')->comment('Where this notification is used (e.g., "Used in ApplicationLogObserver, method handleException")');
            $table->boolean('verified')->default(false)->after('usage_reference')->comment('Whether this notification has been tested and verified with real-world usage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['usage_reference', 'verified']);
        });
    }
};
