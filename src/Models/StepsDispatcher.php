<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StepsDispatcher extends BaseModel
{
    use HasDebuggable, HasLoggable;

    protected $table = 'steps_dispatcher';

    protected $casts = [
        'can_dispatch' => 'boolean',
    ];

    // Optional now; no longer used by the command.
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

    public static function startDispatch(): bool
    {
        // Ensure the singleton row exists without updating timestamps on existing row.
        DB::table('steps_dispatcher')->insertOrIgnore([
            'id' => 1,
            'can_dispatch' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dispatcher = static::query()->find(1);

        // Failsafe: unlock if stuck > 20s.
        if (! $dispatcher->can_dispatch && $dispatcher->updated_at && $dispatcher->updated_at->lt(now()->subSeconds(20))) {
            $dispatcher->update(['can_dispatch' => true]);
        }

        // Atomic lock acquire.
        $acquired = DB::table('steps_dispatcher')
            ->where('id', 1)
            ->where('can_dispatch', true)
            ->update([
                'can_dispatch' => false,
                'updated_at' => now(),
            ]) === 1;

        if (! $acquired) {
            info_if('[StepsDispatcher] Another tick is running; skipping.');

            return false;
        }

        // Tick bookkeeping…
        $startedAt = now();
        Cache::put('steps_dispatcher_tick_start', microtime(true), 300);

        $tick = StepsDispatcherTicks::create([
            'started_at' => $startedAt,
        ]);

        Cache::put('current_tick_id', $tick->id, 300);

        $dispatcher->update([
            'current_tick_id' => $tick->id,
        ]);

        return true;
    }

    public static function endDispatch(int $progress = 0): void
    {
        $dispatcher = static::query()->first();
        if (! $dispatcher) {
            return;
        }

        $tickId = $dispatcher->current_tick_id;

        if ($tickId) {
            $tick = StepsDispatcherTicks::find($tickId);

            if ($tick) {
                $startedAtFloat = Cache::pull('steps_dispatcher_tick_start');

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

            $dispatcher->update(['current_tick_id' => null]);
        }

        // Release the lock no matter what.
        $dispatcher->update(['can_dispatch' => true]);

        Cache::forget('current_tick_id');
        Cache::forget('steps_dispatcher_tick_start');
    }
}
