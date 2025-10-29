<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\User;

final class UserObserver
{
    public function creating(User $model): void
    {
        // $model->cacheChangesForCreate();
    }

    public function created(User $model): void {}

    public function updating(User $model): void
    {
        // $model->cacheChangesForUpdate();
    }

    public function updated(User $model): void {}

    public function deleted(User $model): void {}

    public function forceDeleted(User $model): void {}
}
