<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add WebSocket connection tracking columns to heartbeats table.
 *
 * These columns enable distinguishing between:
 * - Connection alive but API paused (wait for data to resume)
 * - Connection dead (restart needed)
 * - Exchange down (wait for external recovery)
 *
 * Key columns:
 * - connection_status: Current state of the WebSocket connection
 * - last_price_data_at: When actual price data was received (not ping/pong)
 * - last_close_code/reason: Why the connection closed (for diagnostics)
 * - connected_at: When current connection was established
 * - internal_reconnect_attempts: BaseWebsocketClient's internal retry count
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('heartbeats', function (Blueprint $table) {
            // Connection state: connected, reconnecting, disconnected, stale
            $table->string('connection_status', 20)
                ->default('unknown')
                ->after('last_payload')
                ->comment('Current WebSocket connection state');

            // When actual PRICE data was last received (distinct from ping/pong)
            $table->timestamp('last_price_data_at')
                ->nullable()
                ->after('connection_status')
                ->comment('When actual price data was received (not ping/pong)');

            // When current connection was established
            $table->timestamp('connected_at')
                ->nullable()
                ->after('last_price_data_at')
                ->comment('When current WebSocket connection was established');

            // Last close code from WebSocket (RFC 6455)
            // Common codes: 1000=normal, 1001=going away, 1002=protocol error,
            // 1006=abnormal, 1012=service restart, 1013=try again later
            $table->unsignedSmallInteger('last_close_code')
                ->nullable()
                ->after('connected_at')
                ->comment('WebSocket close code from last disconnect');

            // Last close reason text
            $table->string('last_close_reason', 255)
                ->nullable()
                ->after('last_close_code')
                ->comment('WebSocket close reason from last disconnect');

            // Internal reconnect attempts (BaseWebsocketClient level, not supervisor)
            $table->unsignedTinyInteger('internal_reconnect_attempts')
                ->default(0)
                ->after('last_close_reason')
                ->comment('Current reconnect attempt count (0 when connected)');

            // Index for querying stale connections efficiently
            $table->index('connection_status', 'idx_heartbeats_connection_status');
            $table->index('last_price_data_at', 'idx_heartbeats_last_price_data_at');
        });
    }

    public function down(): void
    {
        Schema::table('heartbeats', function (Blueprint $table) {
            $table->dropIndex('idx_heartbeats_connection_status');
            $table->dropIndex('idx_heartbeats_last_price_data_at');
            $table->dropColumn([
                'connection_status',
                'last_price_data_at',
                'connected_at',
                'last_close_code',
                'last_close_reason',
                'internal_reconnect_attempts',
            ]);
        });
    }
};
