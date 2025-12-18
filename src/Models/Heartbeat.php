<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Martingalian\Core\Abstracts\BaseModel;

/**
 * @property int $id
 * @property string $canonical
 * @property int|null $api_system_id
 * @property int|null $account_id
 * @property string|null $group
 * @property Carbon $last_beat_at
 * @property int $beat_count
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ApiSystem|null $apiSystem
 * @property-read Account|null $account
 */
final class Heartbeat extends BaseModel
{
    use HasFactory;

    protected $table = 'heartbeats';

    protected $casts = [
        'api_system_id' => 'integer',
        'account_id' => 'integer',
        'last_beat_at' => 'datetime',
        'beat_count' => 'integer',
        'metadata' => 'array',
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
        ?array $metadata = null
    ): void {
        DB::transaction(function () use ($canonical, $apiSystemId, $accountId, $group, $metadata): void {
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
                ]);
            }
        });
    }
}
