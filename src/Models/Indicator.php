<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;
use Martingalian\Core\Concerns\Indicator\HasScopes;

class Indicator extends BaseModel
{
    use HasDebuggable;
    use HasLoggable;
    use HasScopes;

    protected $casts = [
        'is_apiable' => 'boolean',
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
