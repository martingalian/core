<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Illuminate\Support\Str;
use Martingalian\Core\Concerns\LogsModelChanges;
use Martingalian\Core\Models\Position;

final class PositionObserver
{
    use LogsModelChanges;

    public function creating(Position $model): void
    {
        $model->uuid ??= Str::uuid()->toString();
    }

    public function created(Position $model): void
    {
        $this->logModelCreation($model);
    }

    public function updating(Position $model): void {}

    public function updated(Position $model): void
    {
        $this->logModelUpdate($model);
    }

    public function deleted(Position $model): void {}

    public function forceDeleted(Position $model): void {}
}
