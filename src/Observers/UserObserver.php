<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Concerns\LogsAttributeChanges;
use Martingalian\Core\Models\User;

final class UserObserver
{
    // use LogsAttributeChanges;

    public function creating(User $model): void
    {
        // $model->cacheChangesForCreate();
    }

    public function created(User $model): void
    {
        // $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function updating(User $model): void
    {
        // $model->cacheChangesForUpdate();
    }

    public function updated(User $model): void
    {
        // $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function deleted(User $model): void
    {
        // $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function forceDeleted(User $model): void
    {
        // $this->logChanges($model, self::class, __FUNCTION__);
    }
}
