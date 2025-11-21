<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Concerns\LogsModelChanges;
use Martingalian\Core\Models\ApiSystem;

final class ApiSystemObserver
{
    use LogsModelChanges;

    public function creating(ApiSystem $model): void {}

    public function updating(ApiSystem $model): void {}

    public function created(ApiSystem $model): void
    {
        $this->logModelCreation($model);
    }

    public function updated(ApiSystem $model): void
    {
        $this->logModelUpdate($model);
    }

    public function deleted(ApiSystem $model): void {}

    public function forceDeleted(ApiSystem $model): void {}
}
