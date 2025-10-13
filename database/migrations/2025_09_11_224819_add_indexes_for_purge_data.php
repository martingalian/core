<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createIndexIfNotExists(
            'api_request_logs',
            'api_req_logs_rel_idx',
            fn (Blueprint $table) => $table->index(['relatable_type', 'relatable_id', 'created_at'], 'api_req_logs_rel_idx')
        );

        $this->createIndexIfNotExists(
            'application_logs',
            'app_logs_loggable_idx',
            fn (Blueprint $table) => $table->index(['loggable_type', 'loggable_id', 'created_at'], 'app_logs_loggable_idx')
        );

        $this->createIndexIfNotExists(
            'steps',
            'steps_rel_idx',
            fn (Blueprint $table) => $table->index(['relatable_type', 'relatable_id', 'created_at'], 'steps_rel_idx')
        );

        $this->createIndexIfNotExists(
            'steps_dispatcher_ticks',
            'ticks_created_idx',
            fn (Blueprint $table) => $table->index('created_at', 'ticks_created_idx')
        );

        $this->createIndexIfNotExists(
            'order_history',
            'order_hist_ord_idx',
            fn (Blueprint $table) => $table->index(['order_id', 'created_at'], 'order_hist_ord_idx')
        );
    }

    public function down(): void
    {
        $this->dropIndexIfExists('api_request_logs', 'api_req_logs_rel_idx');
        $this->dropIndexIfExists('application_logs', 'app_logs_loggable_idx');
        $this->dropIndexIfExists('steps', 'steps_rel_idx');
        $this->dropIndexIfExists('steps_dispatcher_ticks', 'ticks_created_idx');
        $this->dropIndexIfExists('order_history', 'order_hist_ord_idx');
    }

    private function createIndexIfNotExists(string $table, string $indexName, callable $callback): void
    {
        if (! $this->indexExists($table, $indexName)) {
            Schema::table($table, $callback);
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($indexName));
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
