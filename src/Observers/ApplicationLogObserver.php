<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Illuminate\Support\Str;
use Martingalian\Core\Models\ApplicationLog;

final class ApplicationLogObserver
{
    public function creating(ApplicationLog $model): void
    {
        if ($model->block_uui === null) {
            $model->block_uuid = Str::uuid()->toString();
        }
    }
}
