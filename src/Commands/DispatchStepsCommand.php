<?php

declare(strict_types=1);

namespace Martingalian\Core\Commands;

use Illuminate\Support\Facades\DB;
use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Completed;
use Martingalian\Core\States\Failed;
use Martingalian\Core\Support\BaseCommand;
use Martingalian\Core\Support\StepDispatcher;
use Throwable;

final class DispatchStepsCommand extends BaseCommand
{
    /**
     * Usage:
     *  php artisan core:dispatch-steps
     *      → Dispatches for ALL groups found in steps_dispatcher.group (including NULL/global if present).
     *
     *  php artisan core:dispatch-steps --group=alpha
     *      → Dispatches only for "alpha".
     *
     *  php artisan core:dispatch-steps --group=alpha,beta
     *  php artisan core:dispatch-steps --group=alpha:beta
     *  php artisan core:dispatch-steps --group="alpha beta|gamma"
     *      → Dispatches for each listed name (comma/colon/semicolon/pipe/whitespace separated).
     *
     *  php artisan core:dispatch-steps --group=alpha --daemon
     *      → Runs continuously for "alpha" group (for supervisor workers).
     *      → Sleeps 1 second when work exists, 5 seconds when idle.
     *
     * Note: When using supervisor, each worker should target a single group (e.g., --group=alpha --daemon)
     * to distribute load across multiple processes.
     */
    protected $signature = 'core:dispatch-steps {--group= : Single group or a list (comma/colon/semicolon/pipe/space separated)} {--daemon : Run continuously with adaptive sleep intervals} {--output : Display command output (silent by default)}';

    protected $description = 'Dispatch step entries for specified group(s) or all groups if none specified.';

    public function handle(): int
    {
        // Daemon mode: run continuously with adaptive sleep intervals
        if ($this->option('daemon')) {
            while (true) {
                $groups = $this->resolveGroups();
                $this->dispatchSteps($groups);

                $sleepDuration = $this->getSleepDuration($groups);
                // $this->verboseInfo("Sleeping for {$sleepDuration} second(s)...");
                sleep($sleepDuration);
            }
        }

        // Single run mode (for cron or manual execution)
        $groups = $this->resolveGroups();
        $this->dispatchSteps($groups);

        return self::SUCCESS;
    }

    /**
     * Truncates storage/logs/laravel.log so each run starts with a clean log.
     */
    public function clearLaravelLog(): void
    {
        $path = storage_path('logs/laravel.log');

        // Ensure directory exists; if not, try to create it quietly.
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Truncate or create the file.
        $ok = @file_put_contents($path, '');
        if ($ok === false) {
            $this->verboseWarn('Could not clear laravel.log (permission or path issue).');

            return;
        }

        $this->verboseInfo('laravel.log cleared.');
    }

    /**
     * Dispatch steps for the specified groups.
     *
     * @param  array<int, string|null>  $groups
     */
    private function dispatchSteps(array $groups): void
    {
        try {
            // Dispatch each group sequentially
            // When run via supervisor, each worker handles one specific group
            foreach ($groups as $group) {
                try {
                    StepDispatcher::dispatch($group);
                    $this->verboseInfo('Dispatched steps for group: '.($group === null ? 'NULL' : $group));
                } catch (Throwable $e) {
                    // Report but continue to next group
                    report($e);
                    $this->verboseError('Error dispatching group '.($group === null ? 'NULL' : $group).': '.$e->getMessage());
                }
            }

            $this->verboseInfo('Dispatched steps for '.count($groups).' group(s).');
        } catch (Throwable $e) {
            report($e);
            $this->verboseError($e->getMessage());
        }
    }

    /**
     * Determine sleep duration based on pending work.
     * Returns 1 second if work exists, 5 seconds if idle.
     *
     * @param  array<int, string|null>  $groups
     */
    private function getSleepDuration(array $groups): int
    {
        // Check if any of the dispatched groups have work to do
        // (steps that are NOT completed and NOT failed)
        $hasPendingWork = Step::query()
            ->whereIn('group', $groups)
            ->whereNotIn('state', [Completed::class, Failed::class])
            ->exists();

        return $hasPendingWork ? 1 : 5;
    }

    /**
     * Resolve the groups to dispatch based on --group option or database.
     *
     * @return array<int, string|null>
     */
    private function resolveGroups(): array
    {
        $opt = $this->option('group');

        if (is_string($opt) && mb_trim($opt) !== '') {
            // Support multiple separators: , : ; | and whitespace
            $groups = preg_split('/[,\s;|:]+/', $opt, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            // Normalize "null"/"NULL" to actual null (to target the global group if desired)
            $groups = array_map(function ($g) {
                $g = mb_trim($g);

                return ($g === '' || strcasecmp($g, 'null') === 0) ? null : $g;
            }, $groups);

            return array_values(array_unique($groups));
        }

        // No --group provided: dispatch for ALL groups present in steps_dispatcher
        // (including a NULL/global row if it exists).
        /** @var array<int, string|null> $groups */
        $groups = DB::table('steps_dispatcher')
            ->select('group')
            ->distinct()
            ->pluck('group')
            ->all();

        // Safety: if table is empty, still try the NULL/global group once.
        if (empty($groups)) {
            $groups = [null];
        }

        return $groups;
    }
}
