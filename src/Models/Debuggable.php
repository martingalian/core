<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Concerns\Debuggable\HandlesDebuggableLogs;
use Illuminate\Database\Eloquent\Model;

class Debuggable extends Model
{
    use HandlesDebuggableLogs;

    public function debuggable()
    {
        return $this->morphTo();
    }
}
