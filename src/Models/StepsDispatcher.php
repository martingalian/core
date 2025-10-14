<?php

namespace Martingalian\Core\Models;

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
    ];

    public static function canDispatch(): bool
    {
        $dispatcher = static::query()->first();
        if (! $dispatcher) {
            return false;
        }

        if (! $dispatcher->can_dispatch && $dispatcher->updated_at && $dispatcher->updated_at->lt(now()->subSeconds(20))) {
            $dispatcher->update(['can_dispatch' => true]);
        }

        return (bool) $dispatcher->can_dispatch;
    }

    public static function startDispatch(?string $group = null): bool
    {
        // Ensure a per-group row exists (creates it if missing)
        DB::table('steps_dispatcher')->insertOrIgnore([
            'group' => $group,
            'can_dispatch' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Load the row we will operate on
        $dispatcher = static::query()
            ->when($group !== null, fn ($q) => $q->where('group', $group), fn ($q) => $q->whereNull('group'))
            ->orderBy('id') // deterministic if multiple rows somehow exist
            ->first();

        if (! $dispatcher) {
            // Fallback: create explicitly if insertOrIgnore didn’t materialize a row
            $dispatcher = new static;
            $dispatcher->group = $group;
            $dispatcher->can_dispatch = true;
            $dispatcher->save();
        }

        // Failsafe: unlock if stuck > 20s.
        if (! $dispatcher->can_dispatch && $dispatcher->updated_at && $dispatcher->updated_at->lt(now()->subSeconds(20))) {
            $dispatcher->update(['can_dispatch' => true]);
        }

        // Atomic lock acquire on THIS row only (prevents cross-process races)
        $acquired = DB::table('steps_dispatcher')
            ->where('id', $dispatcher->id)
            ->where('can_dispatch', true)
            ->update([
                'can_dispatch' => false,
                'updated_at' => now(),
            ]) === 1;

        if (! $acquired) {
            info_if('[StepsDispatcher] Another tick is running; skipping.'.($group ? " [group={$group}]" : ''));

            return false;
        }

        // Tick bookkeeping (per-group cache keys)
        $cacheSuffix = $group ?? 'global';
        Cache::put("steps_dispatcher_tick_start:{$cacheSuffix}", microtime(true), 300);

        $startedAt = now();
        $tick = StepsDispatcherTicks::create([
            'started_at' => $startedAt,
        ]);

        Cache::put("current_tick_id:{$cacheSuffix}", $tick->id, 300);

        // Store linkage on this dispatcher row
        DB::table('steps_dispatcher')
            ->where('id', $dispatcher->id)
            ->update(['current_tick_id' => $tick->id]);

        return true;
    }

    public static function endDispatch(int $progress = 0, ?string $group = null): void
    {
        $dispatcher = static::query()
            ->when($group !== null, fn ($q) => $q->where('group', $group), fn ($q) => $q->whereNull('group'))
            ->orderBy('id')
            ->first();

        if (! $dispatcher) {
            return;
        }

        $tickId = $dispatcher->current_tick_id;

        if ($tickId) {
            $tick = StepsDispatcherTicks::find($tickId);

            if ($tick) {
                $cacheSuffix = $group ?? 'global';
                $startedAtFloat = Cache::pull("steps_dispatcher_tick_start:{$cacheSuffix}");

                if ($startedAtFloat) {
                    $completedAt = now();
                    $durationMs = (int) round((microtime(true) - $startedAtFloat) * 1000);

                    $tick->update([
                        'progress' => $progress,
                        'completed_at' => $completedAt,
                        'duration' => $durationMs,
                    ]);

                    if ($durationMs > 40000) {
                        User::notifyAdminsViaPushover(
                            message: "Dispatch took too long: {$durationMs}ms.",
                            title: 'Step Dispatcher Tick Warning',
                            applicationKey: 'nidavellir_warnings'
                        );
                    }
                }
            }

            // Clear linkage on this same row
            DB::table('steps_dispatcher')
                ->where('id', $dispatcher->id)
                ->update(['current_tick_id' => null]);
        }

        // Release the lock on THIS row
        DB::table('steps_dispatcher')
            ->where('id', $dispatcher->id)
            ->update(['can_dispatch' => true]);

        $cacheSuffix = $group ?? 'global';
        Cache::forget("current_tick_id:{$cacheSuffix}");
        Cache::forget("steps_dispatcher_tick_start:{$cacheSuffix}");
    }
}
