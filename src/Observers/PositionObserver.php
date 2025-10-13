<?php

namespace Martingalian\Core\Observers;

use Martingalian\Core\Concerns\LogsAttributeChanges;
use Martingalian\Core\Models\Position;
use Illuminate\Support\Str;

class PositionObserver
{
    use LogsAttributeChanges;

    public function creating(Position $model): void
    {
        $model->uuid ??= Str::uuid()->toString();
        $model->cacheChangesForCreate();
    }

    public function created(Position $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function updating(Position $model): void
    {
        $model->cacheChangesForUpdate();
    }

    public function updated(Position $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function deleted(Position $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function forceDeleted(Position $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }
}
