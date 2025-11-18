<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\Quote;

final class QuoteObserver
{
    public function creating(Quote $model): void {}

    public function created(Quote $model): void {}

    public function updating(Quote $model): void {}

    public function updated(Quote $model): void {}

    public function deleted(Quote $model): void {}

    public function forceDeleted(Quote $model): void {}
}
