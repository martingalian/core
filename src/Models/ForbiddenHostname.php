<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;

class ForbiddenHostname extends BaseModel
{
    use HasDebuggable;
    use HasLoggable;

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
