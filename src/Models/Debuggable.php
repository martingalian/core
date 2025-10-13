<?php

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Martingalian\Core\Concerns\Debuggable\HandlesDebuggableLogs;

class Debuggable extends Model
{
    use HandlesDebuggableLogs;

    public function debuggable()
    {
        return $this->morphTo();
    }
}
