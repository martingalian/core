<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

final class SlowQuery extends BaseModel
{
    protected $casts = [
        'bindings' => 'array',
        'time_ms' => 'integer',
    ];
}
