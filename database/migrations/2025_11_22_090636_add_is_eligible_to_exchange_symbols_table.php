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
            $table->boolean('is_eligible')->default(false)->after('auto_disabled_reason');
            $table->text('ineligible_reason')->nullable()->after('is_eligible');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->dropColumn(['is_eligible', 'ineligible_reason']);
        });
    }
};
