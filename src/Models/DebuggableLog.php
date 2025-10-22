<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Model;

final class DebuggableLog extends Model
{
    public function debuggable()
    {
        return $this->morphTo();
    }
}
