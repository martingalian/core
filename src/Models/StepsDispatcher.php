<?php

namespace Martingalian\Core\Models;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;

class StepsDispatcher extends BaseModel
{
    use HasDebuggable, HasLoggable;

    protected $table = 'steps_dispatcher';

    protected $casts = [
        'can_dispatch' => 'boolean',
        'last_tick_completed' => 'datetime',
    ];

    protected static function label(?string $group): string
    {
        return $group === null ? 'NULL' : $group;
    }

    /**
     * Selects the next group to dispatch.
     * Preference order:
     *  1) Groups with can_dispatch = true AND updated_at IS NULL (never updated) → pick lowest id
     *  2) Otherwise, among can_dispatch = true → pick the one with oldest updated_at (then lowest id)
     *
     * @return string|null The group name to dispatch (null means the global/NULL group)
     */
    public static function getDispatchGroup(): ?string
    {
        // 1) Prefer groups never updated
        $row = static::query()
            ->where('can_dispatch', true)
            ->whereNull('updated_at')
            ->orderBy('id', 'asc')
            ->first();

        if ($row) {
            info('[StepsDispatcher@getDispatchGroup] Selected NEVER-UPDATED group=' . self::label($row->group) . ' (id=' . $row->id . ').');

            return $row->group;
        }

        // 2) Fall back to oldest updated_at
        $row = static::query()
            ->where('can_dispatch', true)
            ->orderBy('updated_at', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        if (! $row) {
            info('[StepsDispatcher@getDispatchGroup] No eligible group found (none with can_dispatch=true).');

            return null;
        }

        info('[StepsDispatcher@getDispatchGroup] Selected group=' . self::label($row->group) . ' (id=' . $row->id . ', updated_at=' . ($row->updated_at?->toDateTimeString()) . ').');

        return $row->group;
    }

    /**
     * Acquire a per-group lock (creates the group row if missing), open a tick, and record linkage.
     * Adds info() logs whenever can_dispatch flips TRUE/FALSE.
     */
    public static function startDispatch(?string $group = null): bool
    {
        info('[StepsDispatcher@startDispatch] Requested start for group='.self::label($group).'.');

        // Create-or-fetch with uniqueness protection (handles concurrent creators)
        try {
            $dispatcher = static::query()
                ->when($group !== null, fn ($q) => $q->where('group', $group), fn ($q) => $q->whereNull('group'))
                ->orderBy('id')
                ->first();

            if (! $dispatcher) {
                $dispatcher = new static;
                $dispatcher->group = $group;
                $dispatcher->can_dispatch = true; // initial state
                $dispatcher->save();
                info('[StepsDispatcher@startDispatch] Created dispatcher row id='.$dispatcher->id.' for group='.self::label($group).'; can_dispatch=TRUE (initialized).');
            } else {
                info('[StepsDispatcher@startDispatch] Found dispatcher row id='.$dispatcher->id.' for group='.self::label($group).'.');
            }
        } catch (QueryException $e) {
            // If a race caused a duplicate-key error, just fetch the existing row
            info('[StepsDispatcher@startDispatch] QueryException on create (likely race). Falling back to fetch. err='.$e->getCode());
            $dispatcher = static::query()
                ->when($group !== null, fn ($q) => $q->where('group', $group), fn ($q) => $q->whereNull('group'))
                ->orderBy('id')
                ->first();
        }

        if (! isset($dispatcher)) {
            info('[StepsDispatcher@startDispatch] Failed to obtain dispatcher row for group='.self::label($group).'.');

            return false;
        }

        // Failsafe: unlock if stuck > 20s
        if (! $dispatcher->can_dispatch &&
            $dispatcher->updated_at &&
            $dispatcher->updated_at->lt(now()->subSeconds(20))
        ) {
            $dispatcher->update(['can_dispatch' => true]);
            info('[StepsDispatcher@startDispatch] Failsafe set can_dispatch=TRUE on row id='.$dispatcher->id.' (group='.self::label($group).').');
        }

        // Atomic lock acquire on THIS row -> can_dispatch FALSE
        $acquired = DB::table('steps_dispatcher')
            ->where('id', $dispatcher->id)
            ->where('can_dispatch', true)
            ->update([
                'can_dispatch' => false,
                'updated_at' => now(),
            ]) === 1;

        if (! $acquired) {
            info('[StepsDispatcher@startDispatch] Lock NOT acquired (already running) for group='.self::label($group).', row id='.$dispatcher->id.'.');

            return false;
        }

        info('[StepsDispatcher@startDispatch] Set can_dispatch=FALSE (lock acquired) for group='.self::label($group).', row id='.$dispatcher->id.'.');

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

        info('[StepsDispatcher@startDispatch] Tick opened id='.$tick->id.' for group='.self::label($group).', linked to dispatcher id='.$dispatcher->id.'.');

        return true;
    }

    /**
     * Finalize the current tick for this group, stamp completion, and release the per-group lock.
     * Adds info() logs whenever can_dispatch flips back to TRUE.
     */
    public static function endDispatch(int $progress = 0, ?string $group = null): void
    {
        info('[StepsDispatcher@endDispatch] Finalizing tick for group='.self::label($group).' (progress='.$progress.').');

        $dispatcher = static::query()
            ->when($group !== null, fn ($q) => $q->where('group', $group), fn ($q) => $q->whereNull('group'))
            ->orderBy('id')
            ->first();

        if (! $dispatcher) {
            info('[StepsDispatcher@endDispatch] No dispatcher row found for group='.self::label($group).'; nothing to finalize.');

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
                    $durationMs = (int) round((microtime(true) - $startedAtFloat) * 1000);

                    $tick->update([
                        'progress' => $progress,
                        'completed_at' => $completedAt,
                        'duration' => $durationMs,
                    ]);

                    info('[StepsDispatcher@endDispatch] Tick id='.$tick->id.' completed for group='.self::label($group).', duration='.$durationMs.'ms.');

                    if ($durationMs > 40000) {
                        info('[StepsDispatcher@endDispatch] WARNING: long dispatch duration='.$durationMs.'ms for group='.self::label($group).'.');
                        User::notifyAdminsViaPushover(
                            message: "Dispatch took too long: {$durationMs}ms.",
                            title: 'Step Dispatcher Tick Warning',
                            applicationKey: 'nidavellir_warnings'
                        );
                    }
                } else {
                    $tick->update([
                        'progress' => $progress,
                        'completed_at' => $completedAt,
                    ]);

                    info('[StepsDispatcher@endDispatch] Tick id='.$tick->id.' completed for group='.self::label($group).' (no start timestamp; duration unknown).');
                }
            } else {
                info('[StepsDispatcher@endDispatch] Tick id='.$tickId.' not found for group='.self::label($group).'.');
            }
        } else {
            info('[StepsDispatcher@endDispatch] No current_tick_id on dispatcher for group='.self::label($group).'.');
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

        info('[StepsDispatcher@endDispatch] Set can_dispatch=TRUE (lock released) and reset dispatcher id='.$dispatcher->id.' for group='.self::label($group).'.');

        $cacheSuffix = $group ?? 'global';
        Cache::forget("current_tick_id:{$cacheSuffix}");
        Cache::forget("steps_dispatcher_tick_start:{$cacheSuffix}");
    }
}
