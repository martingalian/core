<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Support\NotificationService;

final class StepsDispatcher extends BaseModel
{
    protected $table = 'steps_dispatcher';

    protected $casts = [
        'can_dispatch' => 'boolean',
        'last_tick_completed' => 'datetime',
        'last_selected_at' => 'datetime',
    ];

    /**
     * Selects a dispatch group using round-robin (delegates to getNextGroup).
     * Skips the NULL group and only returns named groups (alpha, beta, gamma, etc.).
     *
     * @return string|null A group name to dispatch (never returns NULL group, only named groups)
     */
    public static function getDispatchGroup(): ?string
    {
        // Check if any groups exist first
        $hasGroups = self::query()
            ->whereNotNull('group')
            ->exists();

        if (! $hasGroups) {
            return null;
        }

        return self::getNextGroup();
    }

    /**
     * Get the next group using round-robin selection (oldest last_selected_at).
     * Updates last_selected_at for the selected group.
     * Falls back to 'alpha' if no groups exist in the table.
     *
     * Uses SKIP LOCKED to avoid waiting on rows locked by other workers,
     * eliminating lock contention that previously caused 8+ second delays.
     *
     * @return string The selected group name
     */
    public static function getNextGroup(): string
    {
        // Use transaction with row-level locking to prevent race conditions
        // when multiple steps are created concurrently
        return DB::transaction(function () {
            // Select group with oldest last_selected_at (null = never selected = highest priority)
            // SKIP LOCKED allows workers to skip rows locked by others instead of waiting
            $dispatcher = self::query()
                ->whereNotNull('group')
                ->orderByRaw('last_selected_at IS NULL DESC') // NULLs first
                ->orderBy('last_selected_at', 'asc')
                ->lock('for update skip locked')
                ->first();

            if (! $dispatcher) {
                // All rows are locked by other workers, or no groups exist
                // Fallback to 'alpha' (will be handled by startDispatch's can_dispatch check)
                return 'alpha';
            }

            // Update last_selected_at for round-robin fairness using raw DB expression
            // to ensure microsecond precision (NOW(6) returns current time with microseconds)
            DB::table('steps_dispatcher')
                ->where('id', $dispatcher->id)
                ->update(['last_selected_at' => DB::raw('NOW(6)')]);

            return $dispatcher->group;
        });
    }

    /**
     * Acquire a per-group lock (creates the group row if missing), open a tick, and record linkage.
     * Adds info() logs whenever can_dispatch flips TRUE/FALSE.
     */
    public static function startDispatch(?string $group = null): bool
    {
        // Create-or-fetch with uniqueness protection (handles concurrent creators)
        try {
            $dispatcher = self::query()
                ->when($group !== null, fn ($q) => $q->where('group', $group), fn ($q) => $q->whereNull('group'))
                ->orderBy('id')
                ->first();

            if (! $dispatcher) {
                $dispatcher = new self;
                $dispatcher->group = $group;
                $dispatcher->can_dispatch = true; // initial state
                $dispatcher->save();
            }
        } catch (QueryException $e) {
            // If a race caused a duplicate-key error, just fetch the existing row
            $dispatcher = self::query()
                ->when($group !== null, fn ($q) => $q->where('group', $group), fn ($q) => $q->whereNull('group'))
                ->orderBy('id')
                ->first();
        }

        if (! isset($dispatcher)) {
            return false;
        }

        // Failsafe: unlock if stuck > 20s
        if (! $dispatcher->can_dispatch &&
            $dispatcher->updated_at &&
            $dispatcher->updated_at->lt(now()->subSeconds(20))
        ) {
            $dispatcher->update(['can_dispatch' => true]);
        }

        // Wrap lock acquisition and tick creation in transaction
        // to prevent orphaned locks if process crashes between lock and tick creation
        return DB::transaction(function () use ($dispatcher, $group) {
            // Atomic lock acquire on THIS row -> can_dispatch FALSE
            $acquired = DB::table('steps_dispatcher')
                ->where('id', $dispatcher->id)
                ->where('can_dispatch', true)
                ->update([
                    'can_dispatch' => false,
                    'updated_at' => now(),
                ]) === 1;

            if (! $acquired) {
                return false;
            }

            // Per-group tick bookkeeping
            $cacheSuffix = $group ?? 'global';
            Cache::put("steps_dispatcher_tick_start:{$cacheSuffix}", microtime(true), 300);

            $startedAt = now();
            $tick = StepsDispatcherTicks::create([
                'started_at' => $startedAt,
                'group' => $group,
            ]);

            Cache::put("current_tick_id:{$cacheSuffix}", $tick->id, 300);

            DB::table('steps_dispatcher')
                ->where('id', $dispatcher->id)
                ->update([
                    'current_tick_id' => $tick->id,
                    'updated_at' => now(),
                ]);

            return true;
        });
    }

    /**
     * Finalize the current tick for this group, stamp completion, and release the per-group lock.
     * Adds info() logs whenever can_dispatch flips back to TRUE.
     */
    public static function endDispatch(int $progress = 0, ?string $group = null): void
    {
        $dispatcher = self::query()
            ->when($group !== null, fn ($q) => $q->where('group', $group), fn ($q) => $q->whereNull('group'))
            ->orderBy('id')
            ->first();

        if (! $dispatcher) {
            return;
        }

        $completedAt = now();
        $tickId = $dispatcher->current_tick_id;

        if ($tickId) {
            $tick = StepsDispatcherTicks::find($tickId);

            if ($tick) {
                $cacheSuffix = $group ?? 'global';
                $startedAtFloat = Cache::pull("steps_dispatcher_tick_start:{$cacheSuffix}");

                if ($startedAtFloat) {
                    $durationMs = max(0, (int) round((microtime(true) - $startedAtFloat) * 1000));

                    $tick->update([
                        'progress' => $progress,
                        'completed_at' => $completedAt,
                        'duration' => $durationMs,
                    ]);

                    if ($durationMs > 40000) {
                        NotificationService::send(
                            user: Martingalian::admin(),
                            message: "Dispatch took too long: {$durationMs}ms.",
                            title: 'Step Dispatcher Tick Warning',
                            deliveryGroup: 'exceptions'
                        );
                    }
                } else {
                    $tick->update([
                        'progress' => $progress,
                        'completed_at' => $completedAt,
                    ]);
                }
            }
        }

        // Release lock -> can_dispatch TRUE (and clear linkage + stamp completion)
        DB::table('steps_dispatcher')
            ->where('id', $dispatcher->id)
            ->update([
                'current_tick_id' => null,
                'can_dispatch' => true,
                'last_tick_completed' => $completedAt,
                'updated_at' => now(),
            ]);

        $cacheSuffix = $group ?? 'global';
        Cache::forget("current_tick_id:{$cacheSuffix}");
        Cache::forget("steps_dispatcher_tick_start:{$cacheSuffix}");
    }

    public static function label(?string $group): string
    {
        return $group === null ? 'NULL' : $group;
    }
}
