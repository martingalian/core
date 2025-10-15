<?php

namespace Martingalian\Core\Commands;

use Illuminate\Console\Command;
use Martingalian\Core\Support\StepDispatcher;

class DispatchStepsCommand extends Command
{
    protected $signature = 'core:dispatch-steps {--group= : Dispatch only steps belonging to the given group}';

    protected $description = 'Dispatch all possible step entries (optionally filtered by --group).';

    public function handle(): int
    {
        try {
            $group = $this->option('group') ?: null;

            if ($group !== null) {
                StepDispatcher::dispatch($group);
                $this->info("Dispatched steps for group: {$group}");
            } else {
                StepDispatcher::dispatch();
                $this->info('Dispatched steps for NULL group.');
            }
        } catch (\Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::SUCCESS;
        }

        return self::SUCCESS;
    }
}
