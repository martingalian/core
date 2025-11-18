<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\ApiSystem;

final class ApiSystemObserver
{
    public function creating(ApiSystem $model): void {}

    public function updating(ApiSystem $model): void {}

    public function created(ApiSystem $model): void {}

    public function updated(ApiSystem $model): void {}

    public function deleted(ApiSystem $model): void {}

    public function forceDeleted(ApiSystem $model): void {}
}
