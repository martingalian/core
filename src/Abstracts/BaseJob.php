<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class BaseJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Max retries for a "always pending" job. Then update to "failed".
    public int $retries = 20;

    // Laravel job timeout configuration.
    public $timeout = 60;
}
