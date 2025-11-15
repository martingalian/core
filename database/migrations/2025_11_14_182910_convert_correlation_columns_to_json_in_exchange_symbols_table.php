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
        // Store existing data temporarily
        $existingData = DB::table('exchange_symbols')
            ->whereNotNull('btc_correlation_pearson')
            ->orWhereNotNull('btc_correlation_spearman')
            ->orWhereNotNull('btc_correlation_rolling')
            ->get(['id', 'btc_correlation_pearson', 'btc_correlation_spearman', 'btc_correlation_rolling']);

        // Drop and recreate columns as JSON
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->dropColumn([
                'btc_correlation_pearson',
                'btc_correlation_spearman',
                'btc_correlation_rolling',
            ]);
        });

        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->json('btc_correlation_pearson')->nullable()->after('indicators_synced_at');
            $table->json('btc_correlation_spearman')->nullable()->after('btc_correlation_pearson');
            $table->json('btc_correlation_rolling')->nullable()->after('btc_correlation_spearman');
        });

        // Migrate existing data to new JSON format
        // Assume old data was calculated on '6h' timeframe (the previous config default)
        foreach ($existingData as $row) {
            $updates = [];

            if ($row->btc_correlation_pearson !== null) {
                $updates['btc_correlation_pearson'] = json_encode(['6h' => (float) $row->btc_correlation_pearson]);
            }

            if ($row->btc_correlation_spearman !== null) {
                $updates['btc_correlation_spearman'] = json_encode(['6h' => (float) $row->btc_correlation_spearman]);
            }

            if ($row->btc_correlation_rolling !== null) {
                $updates['btc_correlation_rolling'] = json_encode(['6h' => (float) $row->btc_correlation_rolling]);
            }

            if (! empty($updates)) {
                DB::table('exchange_symbols')
                    ->where('id', $row->id)
                    ->update($updates);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Store existing JSON data temporarily
        $existingData = DB::table('exchange_symbols')
            ->whereNotNull('btc_correlation_pearson')
            ->orWhereNotNull('btc_correlation_spearman')
            ->orWhereNotNull('btc_correlation_rolling')
            ->get(['id', 'btc_correlation_pearson', 'btc_correlation_spearman', 'btc_correlation_rolling']);

        // Drop and recreate columns as decimal
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->dropColumn([
                'btc_correlation_pearson',
                'btc_correlation_spearman',
                'btc_correlation_rolling',
            ]);
        });

        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->decimal('btc_correlation_pearson', 5, 4)->nullable()->after('indicators_synced_at');
            $table->decimal('btc_correlation_spearman', 5, 4)->nullable()->after('btc_correlation_pearson');
            $table->decimal('btc_correlation_rolling', 5, 4)->nullable()->after('btc_correlation_spearman');
        });

        // Migrate JSON data back to decimal (take 6h value if exists, or first available timeframe)
        foreach ($existingData as $row) {
            $updates = [];

            if ($row->btc_correlation_pearson !== null) {
                $data = json_decode($row->btc_correlation_pearson, true);
                $updates['btc_correlation_pearson'] = $data['6h'] ?? reset($data) ?? null;
            }

            if ($row->btc_correlation_spearman !== null) {
                $data = json_decode($row->btc_correlation_spearman, true);
                $updates['btc_correlation_spearman'] = $data['6h'] ?? reset($data) ?? null;
            }

            if ($row->btc_correlation_rolling !== null) {
                $data = json_decode($row->btc_correlation_rolling, true);
                $updates['btc_correlation_rolling'] = $data['6h'] ?? reset($data) ?? null;
            }

            if (! empty($updates)) {
                DB::table('exchange_symbols')
                    ->where('id', $row->id)
                    ->update($updates);
            }
        }
    }
};
