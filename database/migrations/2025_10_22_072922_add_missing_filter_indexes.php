<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add indexes for commonly filtered columns that were missing proper indexes.
     *
     * These indexes improve performance on WHERE clauses that filter by boolean
     * flags and status columns across various tables.
     */
    public function up(): void
    {
        // exchange_symbols - frequently filtered by is_active and is_tradeable
        Schema::table('exchange_symbols', function (Blueprint $table) {
            if (Schema::hasColumn('exchange_symbols', 'is_active')) {
                $this->createIndexIfNotExists('exchange_symbols', 'idx_exchange_symbols_is_active', function () use ($table) {
                    $table->index('is_active', 'idx_exchange_symbols_is_active');
                });
            }

            if (Schema::hasColumn('exchange_symbols', 'is_tradeable')) {
                $this->createIndexIfNotExists('exchange_symbols', 'idx_exchange_symbols_is_tradeable', function () use ($table) {
                    $table->index('is_tradeable', 'idx_exchange_symbols_is_tradeable');
                });
            }

            // Composite index for common queries filtering by api_system + is_active
            $this->createIndexIfNotExists('exchange_symbols', 'idx_exchange_symbols_api_active', function () use ($table) {
                $table->index(['api_system_id', 'is_active'], 'idx_exchange_symbols_api_active');
            });
        });

        // positions - status is heavily queried but not indexed
        Schema::table('positions', function (Blueprint $table) {
            $this->createIndexIfNotExists('positions', 'idx_positions_status', function () use ($table) {
                $table->index('status', 'idx_positions_status');
            });

            // Composite for account + status (common pattern in Position scopes)
            $this->createIndexIfNotExists('positions', 'idx_positions_account_status', function () use ($table) {
                $table->index(['account_id', 'status'], 'idx_positions_account_status');
            });
        });

        // accounts - can_trade is used to filter active trading accounts
        Schema::table('accounts', function (Blueprint $table) {
            $this->createIndexIfNotExists('accounts', 'idx_accounts_can_trade', function () use ($table) {
                $table->index('can_trade', 'idx_accounts_can_trade');
            });

            // Composite for user + can_trade (common pattern)
            $this->createIndexIfNotExists('accounts', 'idx_accounts_user_can_trade', function () use ($table) {
                $table->index(['user_id', 'can_trade'], 'idx_accounts_user_can_trade');
            });
        });

        // users - is_active and is_admin for filtering
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_active')) {
                $this->createIndexIfNotExists('users', 'idx_users_is_active', function () use ($table) {
                    $table->index('is_active', 'idx_users_is_active');
                });
            }

            if (Schema::hasColumn('users', 'is_admin')) {
                $this->createIndexIfNotExists('users', 'idx_users_is_admin', function () use ($table) {
                    $table->index('is_admin', 'idx_users_is_admin');
                });
            }

            if (Schema::hasColumn('users', 'can_trade')) {
                $this->createIndexIfNotExists('users', 'idx_users_can_trade', function () use ($table) {
                    $table->index('can_trade', 'idx_users_can_trade');
                });
            }
        });

        // api_systems - is_exchange for filtering exchanges vs other API systems
        Schema::table('api_systems', function (Blueprint $table) {
            $this->createIndexIfNotExists('api_systems', 'idx_api_systems_is_exchange', function () use ($table) {
                $table->index('is_exchange', 'idx_api_systems_is_exchange');
            });
        });

        // indicators - is_active and type for filtering
        Schema::table('indicators', function (Blueprint $table) {
            $this->createIndexIfNotExists('indicators', 'idx_indicators_is_active', function () use ($table) {
                $table->index('is_active', 'idx_indicators_is_active');
            });

            $this->createIndexIfNotExists('indicators', 'idx_indicators_type', function () use ($table) {
                $table->index('type', 'idx_indicators_type');
            });

            // Composite for type + is_active (common pattern)
            $this->createIndexIfNotExists('indicators', 'idx_indicators_type_active', function () use ($table) {
                $table->index(['type', 'is_active'], 'idx_indicators_type_active');
            });
        });

        // exchange_symbols - direction is frequently queried
        Schema::table('exchange_symbols', function (Blueprint $table) {
            if (Schema::hasColumn('exchange_symbols', 'direction')) {
                $this->createIndexIfNotExists('exchange_symbols', 'idx_exchange_symbols_direction', function () use ($table) {
                    $table->index('direction', 'idx_exchange_symbols_direction');
                });
            }
        });

        // positions - direction is frequently queried
        Schema::table('positions', function (Blueprint $table) {
            if (Schema::hasColumn('positions', 'direction')) {
                $this->createIndexIfNotExists('positions', 'idx_positions_direction', function () use ($table) {
                    $table->index('direction', 'idx_positions_direction');
                });
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropIndexIfExists('exchange_symbols', 'idx_exchange_symbols_is_active');
        $this->dropIndexIfExists('exchange_symbols', 'idx_exchange_symbols_is_tradeable');
        $this->dropIndexIfExists('exchange_symbols', 'idx_exchange_symbols_api_active');
        $this->dropIndexIfExists('exchange_symbols', 'idx_exchange_symbols_direction');

        $this->dropIndexIfExists('positions', 'idx_positions_status');
        $this->dropIndexIfExists('positions', 'idx_positions_account_status');
        $this->dropIndexIfExists('positions', 'idx_positions_direction');

        $this->dropIndexIfExists('accounts', 'idx_accounts_can_trade');
        $this->dropIndexIfExists('accounts', 'idx_accounts_user_can_trade');

        $this->dropIndexIfExists('users', 'idx_users_is_active');
        $this->dropIndexIfExists('users', 'idx_users_is_admin');
        $this->dropIndexIfExists('users', 'idx_users_can_trade');

        $this->dropIndexIfExists('api_systems', 'idx_api_systems_is_exchange');

        $this->dropIndexIfExists('indicators', 'idx_indicators_is_active');
        $this->dropIndexIfExists('indicators', 'idx_indicators_type');
        $this->dropIndexIfExists('indicators', 'idx_indicators_type_active');
    }

    /**
     * Create an index only if it doesn't already exist.
     */
    private function createIndexIfNotExists(string $table, string $indexName, callable $callback): void
    {
        if (! $this->indexExists($table, $indexName)) {
            $callback();
        }
    }

    /**
     * Drop an index only if it exists.
     */
    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($indexName));
        }
    }

    /**
     * Check if an index exists on a table.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('".$table."')");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
