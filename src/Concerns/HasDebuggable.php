<?php

namespace Martingalian\Core\Concerns;

use Martingalian\Core\Models\Debuggable;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasDebuggable
{
    public function debuggable(): MorphOne
    {
        return $this->morphOne(Debuggable::class, 'debuggable');
    }
}
