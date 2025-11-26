<?php

declare(strict_types=1);

namespace Martingalian\Core\Commands;

use Martingalian\Core\Jobs\Support\ProcessGroupTickJob;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Support\BaseCommand;
use Martingalian\Core\Support\StepDispatcher;
use Throwable;

final class DispatchStepsCommand extends BaseCommand
{
    /**
     * Usage:
     *  php artisan core:dispatch-steps
     *      → Coordinator mode: discovers active groups from steps table and dispatches queue jobs in parallel.
     *
     *  php artisan core:dispatch-steps --group=<uuid> --daemon
     *      → Daemon mode: runs continuously for a specific group (for direct processing without queue).
     *      → Sleeps 1 second when work exists, 5 seconds when idle.
     *
     * Coordinator mode (default):
     *   - Queries distinct groups from steps table that have non-terminal steps
     *   - Dispatches ProcessGroupTickJob for each group to the step-dispatcher queue
     *   - Queue workers process jobs in parallel
     *   - Run via scheduler every second
     *
     * Daemon mode (--daemon with --group):
     *   - Processes a single group directly (no queue)
     *   - Useful for debugging or single-group processing
     */
    protected $signature = 'core:dispatch-steps {--group= : Specific group to process (required for daemon mode)} {--daemon : Run continuously for a specific group} {--output : Display command output (silent by default)}';

    protected $description = 'Coordinator: dispatch queue jobs for active groups. Daemon: process single group directly.';

    public function handle(): int
    {
        // Daemon mode: run continuously for a specific group
        if ($this->option('daemon')) {
            return $this->runDaemonMode();
        }

        // Coordinator mode (default): dispatch queue jobs for active groups
        return $this->runCoordinatorMode();
    }

    /**
     * Coordinator mode: discover active groups and dispatch queue jobs for parallel processing.
     */
    private function runCoordinatorMode(): int
    {
        $activeGroups = $this->getActiveGroups();

        if (empty($activeGroups)) {
            $this->verboseInfo('No active groups found.');

            return self::SUCCESS;
        }

        foreach ($activeGroups as $group) {
            try {
                ProcessGroupTickJob::dispatch($group);
                $this->verboseInfo("Dispatched job for group: {$group}");
            } catch (Throwable $e) {
                report($e);
                $this->verboseError("Error dispatching job for group {$group}: {$e->getMessage()}");
            }
        }

        $this->verboseInfo('Dispatched jobs for '.count($activeGroups).' group(s).');

        return self::SUCCESS;
    }

    /**
     * Daemon mode: run continuously for a specific group (direct processing, no queue).
     */
    private function runDaemonMode(): int
    {
        $group = $this->option('group');

        if (empty($group)) {
            $this->error('Daemon mode requires --group=<group> to be specified.');

            return self::FAILURE;
        }

        $this->verboseInfo("Starting daemon mode for group: {$group}");

        while (true) {
            try {
                StepDispatcher::dispatch($group);
                $this->verboseInfo("Processed tick for group: {$group}");
            } catch (Throwable $e) {
                report($e);
                $this->verboseError("Error processing group {$group}: {$e->getMessage()}");
            }

            $sleepDuration = $this->getSleepDuration($group);
            sleep($sleepDuration);
        }
    }

    /**
     * Get active groups from steps table.
     * Returns groups that have non-terminal steps (pending work).
     *
     * @return array<int, string>
     */
    private function getActiveGroups(): array
    {
        return Step::query()
            ->whereNotIn('state', Step::terminalStepStates())
            ->whereNotNull('group')
            ->distinct()
            ->pluck('group')
            ->all();
    }

    /**
     * Determine sleep duration based on pending work for a specific group.
     * Returns 1 second if work exists, 5 seconds if idle.
     */
    private function getSleepDuration(string $group): int
    {
        $hasPendingWork = Step::query()
            ->where('group', $group)
            ->whereNotIn('state', Step::terminalStepStates())
            ->exists();

        return $hasPendingWork ? 1 : 5;
    }
}
