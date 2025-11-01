<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Jobs\Support\ProcessRepeatersJob;

final class Repeater extends BaseModel
{
    protected $table = 'repeaters';

    protected $casts = [
        'parameters' => 'array',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Process all pending repeaters ready to run
     *
     * @param string|null $queueName Filter by specific queue name
     */
    public static function process(?string $queueName = null): void
    {
        info('Repeater called');
        ProcessRepeatersJob::dispatch($queueName);
    }
}
