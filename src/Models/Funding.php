<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

class Funding extends BaseModel
{
    protected $casts = [
        'date_value' => 'datetime',
    ];
}
