<?php

namespace Martingalian\Core\Observers;

use Martingalian\Core\Concerns\LogsAttributeChanges;
use Martingalian\Core\Models\AccountBalanceHistory;

class AccountBalanceHistoryObserver
{
    use LogsAttributeChanges;

    public function creating(AccountBalanceHistory $model): void
    {
        $model->cacheChangesForCreate();
    }

    public function updating(AccountBalanceHistory $model): void
    {
        $model->cacheChangesForUpdate();
    }

    public function created(AccountBalanceHistory $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function updated(AccountBalanceHistory $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function deleted(AccountBalanceHistory $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function forceDeleted(AccountBalanceHistory $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }
}
