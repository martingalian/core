<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Concerns\LogsAttributeChanges;
use Martingalian\Core\Models\ForbiddenHostname;
use Martingalian\Core\Support\NotificationThrottler;

final class ForbiddenHostnameObserver
{
    use LogsAttributeChanges;

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
        $this->logChanges($model, self::class, __FUNCTION__);

        // Notification is sent by ApiExceptionHelpers::forbid() which has more context
        // about WHY the hostname was forbidden (e.g., 403 error, IP not whitelisted)
    }

    public function updated(ForbiddenHostname $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function deleted(ForbiddenHostname $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function forceDeleted(ForbiddenHostname $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }
}
