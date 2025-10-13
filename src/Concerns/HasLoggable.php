<?php

namespace Martingalian\Core\Concerns;

use Martingalian\Core\Models\ApplicationLog;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasLoggable
{
    public function applicationLogs(): MorphMany
    {
        return $this->morphMany(ApplicationLog::class, 'loggable');
    }

    public function logApplicationEvent(string $event, ?string $sourceClass = null, ?string $sourceMethod = null, ?string $blockUuid = null): ApplicationLog
    {
        // Extract real class name (if sourceClass is passed)
        if ($sourceClass) {
            $sourceClass = class_basename($sourceClass);
        }

        return ApplicationLog::record($event, $this, $sourceClass, $sourceMethod, $blockUuid);
    }
}
