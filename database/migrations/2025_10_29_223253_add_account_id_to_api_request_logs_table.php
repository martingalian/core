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
        Schema::table('api_request_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable()->after('api_system_id');
            $table->index('account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_request_logs', function (Blueprint $table) {
            $table->dropIndex(['account_id']);
            $table->dropColumn('account_id');
        });
    }
};
