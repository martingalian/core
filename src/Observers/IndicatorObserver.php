<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\Indicator;

final class IndicatorObserver
{
    public function creating(Indicator $model): void {}

    public function updating(Indicator $model): void {}

    public function created(Indicator $model): void {}

    public function updated(Indicator $model): void {}

    public function deleted(Indicator $model): void {}

    public function forceDeleted(Indicator $model): void {}
}
