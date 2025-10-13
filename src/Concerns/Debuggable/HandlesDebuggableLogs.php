<?php

namespace Martingalian\Core\Concerns\Debuggable;

use Martingalian\Core\Models\DebuggableLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

trait HandlesDebuggableLogs
{
    /**
     * Write debug output for a model if it is in debug mode.
     */
    public static function debug(Model $model, string $message, ?string $label = null): void
    {
        if (! self::isDebugging($model)) {
            return;
        }

        $class = class_basename($model);
        $id = $model->getKey();
        $labelPart = $label ? " [{$label}]" : '';

        // Log to Laravel log.
        info_if("[DEBUGGABLE] - {$class} [{$id}]{$labelPart} - {$message}");

        // Store in debuggable_logs.
        DebuggableLog::create([
            'debuggable_type' => $model->getMorphClass(),
            'debuggable_id' => $id,
            'label' => $label,
            'message' => $message,
        ]);
    }

    /**
     * Check if the model is marked for debug.
     */
    public static function isDebugging(Model $model): bool
    {
        return self::query()
            ->where('debuggable_type', $model->getMorphClass())
            ->where('debuggable_id', $model->getKey())
            ->exists();
    }
}
