<?php

namespace Martingalian\Core\Observers;

use Martingalian\Core\Concerns\LogsAttributeChanges;
use Martingalian\Core\Models\ApiSystem;

class ApiSystemObserver
{
    use LogsAttributeChanges;

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
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function updated(ApiSystem $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function deleted(ApiSystem $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function forceDeleted(ApiSystem $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }
}
