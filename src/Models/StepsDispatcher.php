<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Support\Throttler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Martingalian\Core\Abstracts\BaseModel;

final class StepsDispatcher extends BaseModel
{
    protected $table = 'steps_dispatcher';

    protected $casts = [
        'can_dispatch' => 'boolean',
        'last_tick_completed' => 'datetime',
        'last_selected_at' => 'datetime',
    ];

    /**
     * Selects a random dispatch group.
     * Skips the NULL group and only returns named groups (alpha, beta, gamma, etc.).
     *
     * @return string|null A random group name to dispatch (never returns NULL group, only named groups)
     */
    public static function getDispatchGroup(): ?string
    {
        // Get all available named groups (exclude NULL group)
        $groups = self::query()
            ->where('can_dispatch', true)
            ->whereNotNull('group')
            ->pluck('group')
            ->all();

        if (empty($groups)) {
            return null;
        }

        // Use PHP's random selection for true randomness
        return collect($groups)->random();
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
                        Throttler::using(NotificationService::class)
                            ->withCanonical('steps_dispatcher')
                            ->execute(function () {
                                NotificationService::send(
                    user: Martingalian::admin(),
                                    message: "Dispatch took too long: {$durationMs}ms.",
                                    title: 'Step Dispatcher Tick Warning',
                                    deliveryGroup: 'exceptions'
                                );
                            });
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
