<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\AccountBalanceHistory;

final class AccountBalanceHistoryObserver
{
    public function creating(AccountBalanceHistory $model): void
    {
        $model->cacheChangesForCreate();
    }

    public function updating(AccountBalanceHistory $model): void
    {
        $model->cacheChangesForUpdate();
    }

    public function created(AccountBalanceHistory $model): void {}

    public function updated(AccountBalanceHistory $model): void {}

    public function deleted(AccountBalanceHistory $model): void {}

    public function forceDeleted(AccountBalanceHistory $model): void {}
}
