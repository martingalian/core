<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Concerns\LogsAttributeChanges;
use Martingalian\Core\Models\ApiSnapshot;

final class ApiSnapshotObserver
{
    use LogsAttributeChanges;

    public function creating(ApiSnapshot $model): void
    {
        $model->cacheChangesForCreate();
    }

    public function updating(ApiSnapshot $model): void
    {
        $model->cacheChangesForUpdate();
    }

    public function created(ApiSnapshot $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function updated(ApiSnapshot $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function deleted(ApiSnapshot $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function forceDeleted(ApiSnapshot $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }
}
