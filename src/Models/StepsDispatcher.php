<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Concerns\HasLoggable;
use Martingalian\Core\Concerns\HasDebuggable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Martingalian\Core\Abstracts\BaseModel;

class StepsDispatcher extends BaseModel
{
    use HasDebuggable, HasLoggable;

    protected $table = "steps_dispatcher";

    protected $casts = [
        "can_dispatch"         => "boolean",
        "last_tick_completed"  => "datetime",
    ];

    // Legacy helper (kept as-is)
    public static function canDispatch(): bool
    {
        $dispatcher = static::query()->first();
        if (! $dispatcher) {
            return false;
        }

        if (! $dispatcher->can_dispatch &&
            $dispatcher->updated_at &&
            $dispatcher->updated_at->lt(now()->subSeconds(20))
        ) {
            $dispatcher->update(["can_dispatch" => true]);
        }

        return (bool) $dispatcher->can_dispatch;
    }

    /**
     * Acquire a per-group lock (creates the group row if missing), open a tick, and record linkage.
     */
    public static function startDispatch(?string $group = null): bool
    {
        // Ensure a dispatcher row exists for this group (NULL = global).
        DB::table("steps_dispatcher")->insertOrIgnore([
            "group"        => $group,
            "can_dispatch" => true,
            "created_at"   => now(),
            "updated_at"   => now(),
        ]);

        // Load the row for this group.
        $dispatcher = static::query()
            ->when($group !== null, fn ($q) => $q->where("group", $group), fn ($q) => $q->whereNull("group"))
            ->orderBy("id")
            ->first();

        // Fallback if insertOrIgnore didn't materialize a row (rare).
        if (! $dispatcher) {
            $dispatcher = new static();
            $dispatcher->group = $group;
            $dispatcher->can_dispatch = true;
            $dispatcher->save();
        }

        // Failsafe: unlock if stuck > 20s.
        if (! $dispatcher->can_dispatch &&
            $dispatcher->updated_at &&
            $dispatcher->updated_at->lt(now()->subSeconds(20))
        ) {
            $dispatcher->update(["can_dispatch" => true]);
        }

        // Atomic lock acquire on THIS dispatcher row.
        $acquired =
            DB::table("steps_dispatcher")
                ->where("id", $dispatcher->id)
                ->where("can_dispatch", true)
                ->update([
                    "can_dispatch" => false,
                    "updated_at"   => now(),
                ]) === 1;

        if (! $acquired) {
            info_if("[StepsDispatcher] Another tick is running; skipping." . ($group ? " [group={$group}]" : ""));
            return false;
        }

        // Tick bookkeeping (per-group cache keys).
        $cacheSuffix = $group ?? "global";
        Cache::put("steps_dispatcher_tick_start:{$cacheSuffix}", microtime(true), 300);

        // Create a tick row for observability (log-only), carrying the group.
        $startedAt = now();
        $tick = StepsDispatcherTicks::create([
            "started_at" => $startedAt,
            "group"      => $group,
        ]);

        // Link the dispatcher to the current tick.
        Cache::put("current_tick_id:{$cacheSuffix}", $tick->id, 300);
        DB::table("steps_dispatcher")
            ->where("id", $dispatcher->id)
            ->update([
                "current_tick_id" => $tick->id,
                "updated_at"      => now(),
            ]);

        return true;
    }

    /**
     * Finalize the current tick for this group, stamp completion, and release the per-group lock.
     */
    public static function endDispatch(int $progress = 0, ?string $group = null): void
    {
        // Load the row for this group.
        $dispatcher = static::query()
            ->when($group !== null, fn ($q) => $q->where("group", $group), fn ($q) => $q->whereNull("group"))
            ->orderBy("id")
            ->first();

        if (! $dispatcher) {
            return;
        }

        $completedAt = now();
        $tickId = $dispatcher->current_tick_id;

        if ($tickId) {
            $tick = StepsDispatcherTicks::find($tickId);

            if ($tick) {
                $cacheSuffix = $group ?? "global";
                $startedAtFloat = Cache::pull("steps_dispatcher_tick_start:{$cacheSuffix}");

                if ($startedAtFloat) {
                    $durationMs = (int) round((microtime(true) - $startedAtFloat) * 1000);

                    $tick->update([
                        "progress"     => $progress,
                        "completed_at" => $completedAt,
                        "duration"     => $durationMs,
                    ]);

                    if ($durationMs > 40000) {
                        User::notifyAdminsViaPushover(
                            message: "Dispatch took too long: {$durationMs}ms.",
                            title: "Step Dispatcher Tick Warning",
                            applicationKey: "nidavellir_warnings"
                        );
                    }
                } else {
                    // No start timestamp available; still mark as completed with progress.
                    $tick->update([
                        "progress"     => $progress,
                        "completed_at" => $completedAt,
                    ]);
                }
            }
        }

        // Single atomic update: release lock, clear linkage, and record completion time on THIS row.
        DB::table("steps_dispatcher")
            ->where("id", $dispatcher->id)
            ->update([
                "current_tick_id"     => null,
                "can_dispatch"        => true,
                "last_tick_completed" => $completedAt,
                "updated_at"          => now(),
            ]);

        $cacheSuffix = $group ?? "global";
        Cache::forget("current_tick_id:{$cacheSuffix}");
        Cache::forget("steps_dispatcher_tick_start:{$cacheSuffix}");
    }
}
