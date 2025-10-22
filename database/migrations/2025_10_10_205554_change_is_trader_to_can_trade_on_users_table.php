<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename "is_trader" to "can_trade" on the "user" table.
     *
     * Note: On some Laravel versions/drivers, renaming columns requires:
     *   composer require doctrine/dbal
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Rename the boolean flag to a clearer name.
            $table->renameColumn('is_trader', 'can_trade');
        });
    }
};
