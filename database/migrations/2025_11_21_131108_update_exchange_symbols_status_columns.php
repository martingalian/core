<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if old columns exist (migration not yet run)
        $hasOldColumns = Schema::hasColumn('exchange_symbols', 'is_active');

        if ($hasOldColumns) {
            Schema::table('exchange_symbols', function (Blueprint $table) {
                // Add new columns
                $table->boolean('is_manually_enabled')->nullable()->default(null)->after('api_system_id');
                $table->boolean('auto_disabled')->default(false)->after('is_manually_enabled');
                $table->string('auto_disabled_reason')->nullable()->after('auto_disabled');

                // Add indexes for new columns
                $table->index('auto_disabled', 'idx_exchange_symbols_auto_disabled');
                $table->index(['api_system_id', 'auto_disabled'], 'idx_exchange_symbols_api_auto_disabled');
            });

            // Migrate data from old columns to new columns
            DB::statement('
                UPDATE exchange_symbols
                SET
                    is_manually_enabled = NULL,
                    auto_disabled = NOT is_active,
                    auto_disabled_reason = CASE
                        WHEN is_active = 0 AND is_eligible = 0 AND ineligible_reason IS NOT NULL
                            THEN ineligible_reason
                        WHEN is_active = 0 AND is_eligible = 0
                            THEN "system_disabled"
                        ELSE NULL
                    END
            ');

            Schema::table('exchange_symbols', function (Blueprint $table) {
                // Drop old columns
                $table->dropColumn(['is_active', 'is_eligible', 'is_tradeable', 'ineligible_reason']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            // Re-add old columns
            $table->boolean('is_active')->default(false)->after('api_system_id');
            $table->boolean('is_tradeable')->default(false)->after('is_active');
            $table->boolean('is_eligible')->default(false)->after('is_tradeable');
            $table->text('ineligible_reason')->nullable()->after('is_eligible');
        });

        // Migrate data back from new columns to old columns
        DB::statement('
            UPDATE exchange_symbols
            SET
                is_active = NOT auto_disabled,
                is_eligible = NOT auto_disabled,
                is_tradeable = NOT auto_disabled,
                ineligible_reason = auto_disabled_reason
        ');

        Schema::table('exchange_symbols', function (Blueprint $table) {
            // Drop new columns and their indexes
            $table->dropIndex('idx_exchange_symbols_auto_disabled');
            $table->dropIndex('idx_exchange_symbols_api_auto_disabled');
            $table->dropColumn(['is_manually_enabled', 'auto_disabled', 'auto_disabled_reason']);
        });
    }
};
