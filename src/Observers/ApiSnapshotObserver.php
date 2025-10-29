<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\ApiSnapshot;

final class ApiSnapshotObserver
{

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
    }

    public function updated(ApiSnapshot $model): void
    {
    }

    public function deleted(ApiSnapshot $model): void
    {
    }

    public function forceDeleted(ApiSnapshot $model): void
    {
    }
}
