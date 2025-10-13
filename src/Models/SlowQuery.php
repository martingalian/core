<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

class SlowQuery extends BaseModel
{
    protected $casts = [
        'bindings' => 'array',
        'time_ms' => 'integer',
    ];
}
