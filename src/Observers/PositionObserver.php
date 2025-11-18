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

    public function created(Position $model): void {}

    public function updating(Position $model): void {}

    public function updated(Position $model): void {}

    public function deleted(Position $model): void {}

    public function forceDeleted(Position $model): void {}
}
