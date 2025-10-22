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
        Schema::table('account_history', function (Blueprint $table) {
            $table->string('account_id')->nullable()->change();
        });

        Schema::table('order_history', function (Blueprint $table) {
            $table->string('order_id')->nullable()->change();
        });
    }
};
