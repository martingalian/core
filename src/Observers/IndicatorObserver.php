<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Concerns\LogsModelChanges;
use Martingalian\Core\Models\Indicator;

final class IndicatorObserver
{
    use LogsModelChanges;

    public function creating(Indicator $model): void {}

    public function updating(Indicator $model): void {}

    public function created(Indicator $model): void
    {
        $this->logModelCreation($model);
    }

    public function updated(Indicator $model): void
    {
        $this->logModelUpdate($model);
    }

    public function deleted(Indicator $model): void {}

    public function forceDeleted(Indicator $model): void {}
}
