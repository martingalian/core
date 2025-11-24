<?php

declare(strict_types=1);

namespace Martingalian\Core\Commands;

use Illuminate\Support\Facades\Artisan;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Server;
use Martingalian\Core\Support\BaseCommand;
use Martingalian\Core\Support\StepDispatcher;

final class SafeToRestartCommand extends BaseCommand
{
    protected $signature = 'martingalian:safe-to-restart';

    protected $description = 'Check if it is safe to restart Horizon/queues (returns true or false)';

    public function handle(): int
    {
        // 1. Get current hostname
        $hostname = gethostname();

        // 2. Validate hostname exists in servers table and is apiable
        $serverExists = Server::where('hostname', $hostname)
            ->where('is_apiable', true)
            ->exists();

        if (! $serverExists) {
            $this->line('false');
            $this->error("❌ Unknown hostname '{$hostname}' - not found in apiable servers");
            $this->warn('Valid hostnames: '.Server::where('is_apiable', true)->pluck('hostname')->implode(', '));
            $this->warn('⛔ Deployment blocked for security');

            return 1;
        }

        // 3. Check if safe to restart
        $canSafelyRestart = StepDispatcher::canSafelyRestart();

        if (! $canSafelyRestart) {
            // 4. Take preventative action based on server type
            if ($hostname === 'ingestion') {
                // Ingestion: Disable step dispatching
                $martingalian = Martingalian::first();
                if ($martingalian) {
                    $martingalian->can_dispatch_steps = false;
                    $martingalian->save();

                    $this->line('false');
                    $this->error('🛑 Ingestion server: Step dispatching disabled');
                    $this->warn('⏳ Waiting for running jobs to complete before deployment...');
                }
            } else {
                // Worker: Enable maintenance mode
                Artisan::call('down', ['--retry' => 60]);

                $this->line('false');
                $this->error('🛑 Worker server: Maintenance mode enabled');
                $this->warn('⏳ Queue workers will finish current jobs, then deployment can proceed');
            }

            return 1; // Non-zero exit code signals deployment to abort
        }

        // 5. All clear - safe to deploy
        $this->line('true');

        return 0;
    }
}
