<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Illuminate\Support\Str;
use Martingalian\Core\Concerns\LogsModelChanges;
use Martingalian\Core\Models\Account;

final class AccountObserver
{
    use LogsModelChanges;

    public function creating(Account $model): void
    {
        $model->uuid ??= Str::uuid()->toString();
    }

    public function updating(Account $model): void {}

    public function created(Account $model): void
    {
        $this->logModelCreation($model);
    }

    public function updated(Account $model): void
    {
        $this->logModelUpdate($model);
    }

    public function deleted(Account $model): void {}

    public function forceDeleted(Account $model): void {}
}
