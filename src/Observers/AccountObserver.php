<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Illuminate\Support\Str;
use Martingalian\Core\Models\Account;

final class AccountObserver
{
    public function creating(Account $model): void
    {
        $model->uuid ??= Str::uuid()->toString();
    }

    public function updating(Account $model): void {}

    public function created(Account $model): void {}

    public function updated(Account $model): void {}

    public function deleted(Account $model): void {}

    public function forceDeleted(Account $model): void {}
}
