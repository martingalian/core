<?php

declare(strict_types=1);

namespace Martingalian\Core\Commands;

use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Martingalian\Core\Support\BaseCommand;
use Martingalian\Core\Support\StepDispatcher;
use Throwable;

final class DispatchStepsCommand extends BaseCommand
{
    /**
     * Usage:
     *  php artisan core:dispatch-steps
     *      → Dispatches for ALL groups found in steps_dispatcher.group (including NULL/global if present).
     *      → All groups are dispatched IN PARALLEL using Laravel's Concurrency facade.
     *
     *  php artisan core:dispatch-steps --group=alpha
     *      → Dispatches only for "alpha".
     *
     *  php artisan core:dispatch-steps --group=alpha,beta
     *  php artisan core:dispatch-steps --group=alpha:beta
     *  php artisan core:dispatch-steps --group="alpha beta|gamma"
     *      → Dispatches for each listed name (comma/colon/semicolon/pipe/whitespace separated).
     *      → All specified groups are dispatched IN PARALLEL.
     */
    protected $signature = 'core:dispatch-steps {--group= : Single group or a list (comma/colon/semicolon/pipe/space separated)} {--output : Display command output (silent by default)}';

    protected $description = 'Dispatch all possible step entries in parallel (optionally filtered by --group).';

    public function handle(): int
    {
        try {
            $groups = $this->resolveGroups();

            // Dispatch all groups IN PARALLEL using Laravel's Concurrency facade.
            // Each group runs in its own PHP process, so:
            // - If delta takes 9 seconds, it doesn't block alpha, beta, etc.
            // - Per-group locking (StepsDispatcher::startDispatch) prevents race conditions
            // - Each closure is wrapped in try-catch for error isolation
            Concurrency::run(
                collect($groups)
                    ->map(function ($group) {
                        return function () use ($group) {
                            try {
                                StepDispatcher::dispatch($group);
                                info('Dispatched steps for group: '.($group === null ? 'NULL' : $group));
                            } catch (Throwable $e) {
                                // Report but don't rethrow - let other groups continue
                                report($e);
                            }
                        };
                    })
                    ->all()
            );

            $this->verboseInfo('Dispatched steps for '.count($groups).' group(s) in parallel.');
        } catch (Throwable $e) {
            report($e);
            $this->verboseError($e->getMessage());

            return self::SUCCESS;
        }

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
