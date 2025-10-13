<?php

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\ApplicationLog;
use Illuminate\Support\Str;

class ApplicationLogObserver
{
    public function creating(ApplicationLog $model): void
    {
        if ($model->block_uui === null) {
            $model->block_uuid = Str::uuid()->toString();
        }
    }
}
