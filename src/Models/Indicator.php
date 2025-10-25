<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;
use Martingalian\Core\Concerns\Indicator\HasScopes;

final class Indicator extends BaseModel
{
    use HasDebuggable;
    use HasLoggable;
    use HasScopes;

    protected $casts = [
        'is_computed' => 'boolean',
        'is_active' => 'boolean',
        'parameters' => 'array',
    ];

    public function steps()
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }
}
