<?php

namespace Martingalian\Core\Observers;

use Martingalian\Core\Concerns\LogsAttributeChanges;
use Martingalian\Core\Models\Account;

class AccountObserver
{
    use LogsAttributeChanges;

    public function creating(Account $model): void
    {
        $model->cacheChangesForCreate();
    }

    public function updating(Account $model): void
    {
        $model->cacheChangesForUpdate();
    }

    public function created(Account $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function updated(Account $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function deleted(Account $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function forceDeleted(Account $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }
}
