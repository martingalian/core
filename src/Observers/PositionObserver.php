<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Illuminate\Support\Str;
use Martingalian\Core\Models\Position;

final class PositionObserver
{
    public function creating(Position $model): void
    {
        $model->uuid ??= Str::uuid()->toString();
    }
}
