<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

final class Funding extends BaseModel
{
    protected $casts = [
        'date_value' => 'datetime',
    ];
}
