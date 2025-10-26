<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Concerns\LogsAttributeChanges;
use Martingalian\Core\Models\ForbiddenHostname;
use Martingalian\Core\Support\Martingalian;

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

        Martingalian::notifyAdmins(
            message: "[{$model->id}] - Forbidden Hostname was added. IP: {$model->ip_address}",
            title: 'Forbidden hostname was added to the database',
            deliveryGroup: 'exceptions'
        );
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
