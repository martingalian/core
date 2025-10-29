<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\ForbiddenHostname;

final class ForbiddenHostnameObserver
{
    public function creating(ForbiddenHostname $model): void
    {
        $model->cacheChangesForCreate();
    }

    public function updating(ForbiddenHostname $model): void
    {
        $model->cacheChangesForUpdate();
    }

    public function created(ForbiddenHostname $model): void
    {

        // Notification is sent by ApiExceptionHelpers::forbid() which has more context
        // about WHY the hostname was forbidden (e.g., 403 error, IP not whitelisted)
    }

    public function updated(ForbiddenHostname $model): void {}

    public function deleted(ForbiddenHostname $model): void {}

    public function forceDeleted(ForbiddenHostname $model): void {}
}
