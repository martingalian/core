<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Carbon\Carbon;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Jobs\Support\ProcessRepeatersJob;

/**
 * @property int $id
 * @property string $class
 * @property array|null $parameters
 * @property string $queue
 * @property Carbon $next_run_at
 * @property int $attempts
 * @property int $max_attempts
 * @property Carbon|null $last_run_at
 * @property string|null $last_error
 * @property string|null $status
 * @property string $retry_strategy
 * @property int $retry_interval_minutes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Repeater extends BaseModel
{
    protected $table = 'repeaters';

    protected $guarded = [];

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
        ProcessRepeatersJob::dispatch($queueName);
    }
}
