<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphOne;
use Martingalian\Core\Models\Debuggable;

trait HasDebuggable
{
    public function debuggable(): MorphOne
    {
        return $this->morphOne(Debuggable::class, 'debuggable');
    }
}
