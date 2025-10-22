<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Martingalian\Core\Concerns\Debuggable\HandlesDebuggableLogs;

final class Debuggable extends Model
{
    use HandlesDebuggableLogs;

    public function debuggable()
    {
        return $this->morphTo();
    }
}
