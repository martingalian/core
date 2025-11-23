<?php

declare(strict_types=1);

namespace Martingalian\Core\Commands;

use Martingalian\Core\Support\BaseCommand;
use Martingalian\Core\Support\StepDispatcher;

final class SafeToRestartCommand extends BaseCommand
{
    protected $signature = 'martingalian:safe-to-restart';

    protected $description = 'Check if it is safe to restart Horizon/queues (returns true or false)';

    public function handle(): int
    {
        $canSafelyRestart = StepDispatcher::canSafelyRestart();

        $this->line($canSafelyRestart ? 'true' : 'false');

        return 0;
    }
}
