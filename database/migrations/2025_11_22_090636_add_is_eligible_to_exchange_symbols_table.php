<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->boolean('has_taapi_data')->default(false)->after('auto_disabled_reason');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->dropColumn('has_taapi_data');
        });
    }
};
