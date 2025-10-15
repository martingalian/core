<?php

namespace Martingalian\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Martingalian\Core\Support\StepDispatcher;

class DispatchStepsCommand extends Command
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
     */
    protected $signature = 'core:dispatch-steps {--group= : Single group or a list (comma/colon/semicolon/pipe/space separated)}';

    protected $description = 'Dispatch all possible step entries (optionally filtered by --group).';

    public function handle(): int
    {
        try {
            $opt = $this->option('group');

            if (is_string($opt) && trim($opt) !== '') {
                // Support multiple separators: , : ; | and whitespace
                $groups = preg_split('/[,\s;|:]+/', $opt, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                // Normalize "null"/"NULL" to actual null (to target the global group if desired)
                $groups = array_map(function ($g) {
                    $g = trim($g);
                    return ($g === '' || strcasecmp($g, 'null') === 0) ? null : $g;
                }, $groups);
                $groups = array_values(array_unique($groups));

                foreach ($groups as $group) {
                    StepDispatcher::dispatch($group);
                    $this->info('Dispatched steps for group: ' . ($group === null ? 'NULL' : $group));
                }

                return self::SUCCESS;
            }

            // No --group provided: dispatch for ALL groups present in steps_dispatcher
            // (including a NULL/global row if it exists).
            $groups = DB::table('steps_dispatcher')
                ->select('group')
                ->distinct()
                ->pluck('group')
                ->all();

            // Safety: if table is empty, still try the NULL/global group once.
            if (empty($groups)) {
                $groups = [null];
            }

            foreach ($groups as $group) {
                StepDispatcher::dispatch($group);
                $this->info('Dispatched steps for group: ' . ($group === null ? 'NULL' : $group));
            }
        } catch (\Throwable $e) {
            report($e);
            $this->error($e->getMessage());
            return self::SUCCESS;
        }

        return self::SUCCESS;
    }
}
