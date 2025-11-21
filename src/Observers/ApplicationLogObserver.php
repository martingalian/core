<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Models\ApplicationLog;

final class ApplicationLogObserver
{
    /**
     * Handle the "created" event - logs all initial attribute values.
     * Respects skipLogging() logic just like updated() does.
     */
    public function created(BaseModel $model): void
    {
        // Skip if logging is globally disabled
        if (! ApplicationLog::isEnabled()) {
            return;
        }
        foreach ($model->getAttributes() as $attribute => $value) {
            // Check if should skip this attribute
            if ($this->shouldSkipLogging($model, $attribute, null, $value)) {
                continue;
            }

            ApplicationLog::create([
                'loggable_type' => get_class($model),
                'loggable_id' => $model->getKey(),
                'event_type' => 'attribute_created',
                'attribute_name' => $attribute,
                'previous_value' => null,
                'new_value' => $value,
                'message' => "Attribute \"{$attribute}\" created with value: ".$this->formatValue($value),
            ]);
        }
    }

    /**
     * Handle the "updated" event - logs attribute changes.
     * Respects skipLogging() logic.
     */
    public function updated(BaseModel $model): void
    {
        // Skip if logging is globally disabled
        if (! ApplicationLog::isEnabled()) {
            return;
        }

        // Only log if there were actual changes
        if (empty($model->getChanges())) {
            return;
        }

        foreach ($model->getChanges() as $attribute => $newValue) {
            $oldValue = $model->getOriginal($attribute);

            // Check if should skip this attribute
            if ($this->shouldSkipLogging($model, $attribute, $oldValue, $newValue)) {
                continue;
            }

            ApplicationLog::create([
                'loggable_type' => get_class($model),
                'loggable_id' => $model->getKey(),
                'event_type' => 'attribute_changed',
                'attribute_name' => $attribute,
                'previous_value' => $oldValue,
                'new_value' => $newValue,
                'message' => $this->buildChangeMessage($attribute, $oldValue, $newValue),
            ]);
        }
    }

    protected function shouldSkipLogging(BaseModel $model, string $attribute, mixed $oldValue, mixed $newValue): bool
    {
        // Level 1: Check static blacklist
        $skipsLogging = $model->skipsLogging ?? [];
        if (in_array($attribute, $skipsLogging)) {
            return true; // Skip it
        }

        // Level 2: Check dynamic skipLogging() method
        if (method_exists($model, 'skipLogging')) {
            // If skipLogging() returns TRUE, we skip logging
            if ($model->skipLogging($attribute, $oldValue, $newValue) === true) {
                return true; // Skip it
            }
        }

        return false; // Don't skip = LOG IT
    }

    protected function buildChangeMessage(string $attribute, mixed $oldValue, mixed $newValue): string
    {
        $old = $this->formatValue($oldValue);
        $new = $this->formatValue($newValue);

        if ($oldValue === null && $newValue !== null) {
            return "Attribute \"{$attribute}\" changed from null to {$new}";
        }

        if ($oldValue !== null && $newValue === null) {
            return "Attribute \"{$attribute}\" changed from {$old} to null";
        }

        return "Attribute \"{$attribute}\" changed from {$old} to {$new}";
    }

    protected function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }
}
