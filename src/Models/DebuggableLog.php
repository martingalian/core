<?php

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Model;

class DebuggableLog extends Model
{
    public function debuggable()
    {
        return $this->morphTo();
    }
}
