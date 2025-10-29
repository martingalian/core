<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\ApiSystem;

final class ApiSystemObserver
{

    public function creating(ApiSystem $model): void
    {
        $model->cacheChangesForCreate();
    }

    public function updating(ApiSystem $model): void
    {
        $model->cacheChangesForUpdate();
    }

    public function created(ApiSystem $model): void
    {
    }

    public function updated(ApiSystem $model): void
    {
    }

    public function deleted(ApiSystem $model): void
    {
    }

    public function forceDeleted(ApiSystem $model): void
    {
    }
}
