<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Models\ApplicationLog;
use Martingalian\Core\Support\ValueNormalizer;

final class ApplicationLogObserver
{
    /**
     * Handle the "created" event - logs all initial attribute values.
     * Uses RAW values from getAttributes() (not cast).
     */
    public function created(BaseModel $model): void
    {
        // Skip if logging is globally disabled
        if (! ApplicationLog::isEnabled()) {
            return;
        }

        // Use getAttributes() to get RAW database values (no casts)
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
     * Uses RAW values from getRawOriginal() and getAttributes() (not cast).
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
            // Get RAW values (not cast) for accurate comparison
            $oldValueRaw = $model->getRawOriginal($attribute);
            $newValueRaw = $model->getAttributes()[$attribute] ?? null;

            // Check if should skip this attribute (using RAW values)
            if ($this->shouldSkipLogging($model, $attribute, $oldValueRaw, $newValueRaw)) {
                continue;
            }

            ApplicationLog::create([
                'loggable_type' => get_class($model),
                'loggable_id' => $model->getKey(),
                'event_type' => 'attribute_changed',
                'attribute_name' => $attribute,
                'previous_value' => $oldValueRaw,
                'new_value' => $newValueRaw,
                'message' => $this->buildChangeMessage($attribute, $oldValueRaw, $newValueRaw),
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

        // Level 2: Semantic equality check (handles type coercion for numerics/JSON)
        // This prevents false positives like "5.00000000" vs 5, or {"a":1,"b":2} vs {"b":2,"a":1}
        if (ValueNormalizer::areEqual($oldValue, $newValue)) {
            return true; // Values are semantically equal - skip logging
        }

        // Level 3: Check dynamic skipLogging() method (model-specific logic)
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
