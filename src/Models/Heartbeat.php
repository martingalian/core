<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Martingalian\Core\Abstracts\BaseModel;
use Throwable;

/**
 * @property int $id
 * @property string $canonical
 * @property int|null $api_system_id
 * @property int|null $account_id
 * @property string|null $group
 * @property Carbon $last_beat_at
 * @property int $beat_count
 * @property array<string, mixed>|null $metadata
 * @property string|null $last_payload
 * @property string $connection_status
 * @property Carbon|null $last_price_data_at
 * @property Carbon|null $connected_at
 * @property int|null $last_close_code
 * @property string|null $last_close_reason
 * @property int $internal_reconnect_attempts
 * @property float|null $memory_usage_mb
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ApiSystem|null $apiSystem
 * @property-read Account|null $account
 */
final class Heartbeat extends BaseModel
{
    use HasFactory;

    // Connection status constants
    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_RECONNECTING = 'reconnecting';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_STALE = 'stale';

    protected $table = 'heartbeats';

    protected $casts = [
        'api_system_id' => 'integer',
        'account_id' => 'integer',
        'last_beat_at' => 'datetime',
        'beat_count' => 'integer',
        'metadata' => 'array',
        'last_price_data_at' => 'datetime',
        'connected_at' => 'datetime',
        'last_close_code' => 'integer',
        'internal_reconnect_attempts' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the API system associated with this heartbeat.
     *
     * @return BelongsTo<ApiSystem, $this>
     */
    public function apiSystem(): BelongsTo
    {
        return $this->belongsTo(ApiSystem::class);
    }

    /**
     * Get the account associated with this heartbeat.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the supervisor worker program name from config for an exchange/group.
     *
     * Used by CheckStaleDataCommand to restart stale WebSocket workers.
     */
    public static function getSupervisorWorker(string $exchangeCanonical, ?string $group = null): ?string
    {
        if ($group !== null) {
            return config("martingalian.websocket_workers.{$exchangeCanonical}.{$group}");
        }

        return config("martingalian.websocket_workers.{$exchangeCanonical}");
    }

    /**
     * Record a heartbeat for a given process.
     *
     * Uses upsert pattern: creates row if not exists, updates if exists.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public static function beat(
        string $canonical,
        ?int $apiSystemId = null,
        ?int $accountId = null,
        ?string $group = null,
        ?array $metadata = null,
        ?string $lastPayload = null,
        ?float $memoryUsageMb = null
    ): void {
        self::executeWithDeadlockRetry(function () use ($canonical, $apiSystemId, $accountId, $group, $metadata, $lastPayload, $memoryUsageMb): void {
            $existing = self::query()
                ->where('canonical', $canonical)
                ->where('api_system_id', $apiSystemId)
                ->where('account_id', $accountId)
                ->where('group', $group)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                /** @var Heartbeat $existing */
                $updateData = [
                    'last_beat_at' => now(),
                    'beat_count' => $existing->beat_count + 1,
                    'last_payload' => $lastPayload,
                    'memory_usage_mb' => $memoryUsageMb,
                ];

                // Merge new metadata with existing, but clear restart tracking
                // since a successful beat means the worker is healthy again
                $existingMetadata = $existing->metadata ?? [];
                unset($existingMetadata['restart_attempts'], $existingMetadata['last_restart_at']);

                if ($metadata !== null) {
                    $updateData['metadata'] = array_merge($existingMetadata, $metadata);
                } elseif (! empty($existingMetadata)) {
                    $updateData['metadata'] = $existingMetadata;
                }

                $existing->update($updateData);
            } else {
                self::query()->create([
                    'canonical' => $canonical,
                    'api_system_id' => $apiSystemId,
                    'account_id' => $accountId,
                    'group' => $group,
                    'last_beat_at' => now(),
                    'beat_count' => 1,
                    'metadata' => $metadata,
                    'last_payload' => $lastPayload,
                    'memory_usage_mb' => $memoryUsageMb,
                ]);
            }
        });
    }

    /**
     * Update connection status for a heartbeat.
     *
     * This is called by UpdatePricesCommand when connection state changes:
     * - Connected: WebSocket connection established
     * - Reconnecting: Connection closed, attempting internal reconnect
     * - Disconnected: Max reconnect attempts reached
     * - Stale: Connection open but no messages received (zombie)
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public static function updateConnectionStatus(
        string $canonical,
        ?int $apiSystemId,
        ?string $group,
        string $status,
        ?int $closeCode = null,
        ?string $closeReason = null,
        int $reconnectAttempts = 0,
        ?array $metadata = null
    ): void {
        self::executeWithDeadlockRetry(function () use ($canonical, $apiSystemId, $group, $status, $closeCode, $closeReason, $reconnectAttempts, $metadata): void {
            $heartbeat = self::query()
                ->where('canonical', $canonical)
                ->where('api_system_id', $apiSystemId)
                ->where('group', $group)
                ->lockForUpdate()
                ->first();

            if (! $heartbeat) {
                // Create heartbeat if it doesn't exist (first connection)
                self::query()->create([
                    'canonical' => $canonical,
                    'api_system_id' => $apiSystemId,
                    'group' => $group,
                    'last_beat_at' => now(),
                    'beat_count' => 0,
                    'connection_status' => $status,
                    'connected_at' => $status === self::STATUS_CONNECTED ? now() : null,
                    'last_close_code' => $closeCode,
                    'last_close_reason' => $closeReason,
                    'internal_reconnect_attempts' => $reconnectAttempts,
                    'metadata' => $metadata,
                ]);

                return;
            }

            /** @var Heartbeat $heartbeat */
            $updateData = [
                'connection_status' => $status,
                'internal_reconnect_attempts' => $reconnectAttempts,
            ];

            // Set connected_at when transitioning to connected
            if ($status === self::STATUS_CONNECTED) {
                $updateData['connected_at'] = now();
                $updateData['last_close_code'] = null;
                $updateData['last_close_reason'] = null;
                $updateData['internal_reconnect_attempts'] = 0;
            }

            // Store close info when transitioning to reconnecting/disconnected
            if (in_array($status, [self::STATUS_RECONNECTING, self::STATUS_DISCONNECTED])) {
                if ($closeCode !== null) {
                    $updateData['last_close_code'] = $closeCode;
                }
                if ($closeReason !== null) {
                    $updateData['last_close_reason'] = $closeReason;
                }
            }

            // Merge metadata if provided
            if ($metadata !== null) {
                $existingMetadata = $heartbeat->metadata ?? [];
                $updateData['metadata'] = array_merge($existingMetadata, $metadata);
            }

            $heartbeat->update($updateData);
        });
    }

    /**
     * Record that actual price data was received (not just ping/pong).
     *
     * This is distinct from beat() which fires on ANY valid message.
     * Used to detect "connection alive but API paused" scenarios.
     */
    public static function recordPriceData(
        string $canonical,
        ?int $apiSystemId,
        ?string $group
    ): void {
        self::query()
            ->where('canonical', $canonical)
            ->where('api_system_id', $apiSystemId)
            ->where('group', $group)
            ->update(['last_price_data_at' => now()]);
    }

    /**
     * Determine if this heartbeat indicates the connection should be restarted.
     *
     * Returns array with 'should_restart' boolean and 'reason' explanation.
     *
     * @return array{
     *     should_restart: bool,
     *     reason: string,
     *     wait_suggested: bool,
     *     connection_status: string|null,
     *     last_beat_seconds_ago: int|null,
     *     last_price_data_seconds_ago: int|null,
     *     last_close_code?: int|null,
     *     last_close_reason?: string|null
     * }
     */
    public function analyzeRestartDecision(int $priceDataThresholdSeconds = 60): array
    {
        // Calculate ages upfront for all return paths
        $lastBeatAge = $this->last_beat_at ? (int) now()->diffInSeconds($this->last_beat_at) : null;
        $lastPriceAge = $this->last_price_data_at ? (int) now()->diffInSeconds($this->last_price_data_at) : null;

        // Base diagnostic info included in all returns
        $baseInfo = [
            'connection_status' => $this->connection_status,
            'last_beat_seconds_ago' => $lastBeatAge,
            'last_price_data_seconds_ago' => $lastPriceAge,
        ];

        // If disconnected (max reconnects exhausted), restart is needed
        if ($this->connection_status === self::STATUS_DISCONNECTED) {
            return array_merge($baseInfo, [
                'should_restart' => true,
                'reason' => 'Connection disconnected after max internal reconnect attempts',
                'wait_suggested' => false,
            ]);
        }

        // If reconnecting, let the internal mechanism try first
        if ($this->connection_status === self::STATUS_RECONNECTING) {
            $attempts = $this->internal_reconnect_attempts;

            return array_merge($baseInfo, [
                'should_restart' => false,
                'reason' => "Internal reconnect in progress (attempt {$attempts}/5)",
                'wait_suggested' => true,
            ]);
        }

        // If connected but no price data, check if connection is receiving anything
        if ($this->connection_status === self::STATUS_CONNECTED) {
            $lastBeatAgeForCheck = $lastBeatAge ?? PHP_INT_MAX;
            $lastPriceAgeForCheck = $lastPriceAge ?? PHP_INT_MAX;

            // Connection healthy (receiving messages) but no price data
            if ($lastBeatAgeForCheck < $priceDataThresholdSeconds && $lastPriceAgeForCheck > $priceDataThresholdSeconds) {
                // Check if close code suggests we should wait
                if (in_array($this->last_close_code, [1012, 1013])) {
                    return array_merge($baseInfo, [
                        'should_restart' => false,
                        'reason' => 'Exchange indicated service restart/try again later (code '.$this->last_close_code.')',
                        'wait_suggested' => true,
                    ]);
                }

                return array_merge($baseInfo, [
                    'should_restart' => false,
                    'reason' => 'Connection healthy but API paused - receiving heartbeats but no price data',
                    'wait_suggested' => true,
                ]);
            }

            // Connection not receiving any messages (true stale)
            if ($lastBeatAgeForCheck > $priceDataThresholdSeconds) {
                return array_merge($baseInfo, [
                    'should_restart' => true,
                    'reason' => "Connection stale - no messages received for {$lastBeatAgeForCheck}s",
                    'wait_suggested' => false,
                ]);
            }

            // Healthy
            return array_merge($baseInfo, [
                'should_restart' => false,
                'reason' => 'Connection healthy and receiving price data',
                'wait_suggested' => false,
            ]);
        }

        // Stale status (zombie connection detected internally)
        if ($this->connection_status === self::STATUS_STALE) {
            return array_merge($baseInfo, [
                'should_restart' => true,
                'reason' => 'Zombie connection detected - open but not receiving data',
                'wait_suggested' => false,
            ]);
        }

        // Unknown status - be conservative, restart
        return array_merge($baseInfo, [
            'should_restart' => true,
            'reason' => 'Unknown connection status',
            'wait_suggested' => false,
        ]);
    }

    /**
     * Get a human-readable description of the close code.
     */
    public static function describeCloseCode(?int $code): string
    {
        return match ($code) {
            null => 'No close code',
            1000 => 'Normal closure',
            1001 => 'Going away (server shutting down)',
            1002 => 'Protocol error',
            1003 => 'Unsupported data',
            1005 => 'No status received',
            1006 => 'Abnormal closure (connection lost)',
            1007 => 'Invalid frame payload data',
            1008 => 'Policy violation',
            1009 => 'Message too big',
            1010 => 'Mandatory extension missing',
            1011 => 'Internal server error',
            1012 => 'Service restart',
            1013 => 'Try again later',
            1014 => 'Bad gateway',
            1015 => 'TLS handshake failure',
            default => "Unknown code ({$code})",
        };
    }

    /**
     * Execute a callback with automatic retry on database deadlock.
     *
     * MySQL deadlocks (error 1213) can occur when multiple processes
     * attempt to lock the same rows simultaneously. This helper retries
     * the transaction with exponential backoff to resolve contention.
     *
     * @param  callable  $callback  The transaction callback to execute
     * @param  int  $maxAttempts  Maximum retry attempts (default 3)
     *
     * @throws Throwable Re-throws exception after max attempts or for non-deadlock errors
     */
    public static function executeWithDeadlockRetry(callable $callback, int $maxAttempts = 3): void
    {
        $attempts = 0;

        while (true) {
            try {
                DB::transaction($callback);

                return;
            } catch (QueryException $e) {
                $attempts++;

                // Check if it's a deadlock error (MySQL error 1213)
                $isDeadlock = str_contains($e->getMessage(), '1213 Deadlock found');

                if (! $isDeadlock || $attempts >= $maxAttempts) {
                    throw $e;
                }

                // Random backoff: 10-50ms * attempt number
                usleep(random_int(10000, 50000) * $attempts);
            }
        }
    }
}
