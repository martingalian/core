<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

trait LogsAttributeChanges
{
    public function logChanges(Model $model, ?string $sourceClass = null, ?string $sourceMethod = null, ?string $blockUuid = null): void
    {
        $prefix = '';
        if ($sourceClass || $sourceMethod) {
            $sourceClass = class_basename($sourceClass ?? '');
            $prefix = '['.($sourceClass ?? '').($sourceMethod ? '.'.$sourceMethod : '').'] - ';
        }

        $blockUuid ??= Str::uuid()->toString();

        $defaultExcluded = ['created_at', 'updated_at', 'deleted_at', 'id'];

        $excluded = defined(get_class($model).'::LOG_EXCLUDED_ATTRIBUTES')
            ? array_merge($defaultExcluded, $model::LOG_EXCLUDED_ATTRIBUTES)
            : $defaultExcluded;

        $rawChanges = method_exists($model, 'getCachedAttributeChanges')
            ? $model->getCachedAttributeChanges()
            : [];

        $attributes = array_diff(array_keys($rawChanges), $excluded);

        $custom = method_exists($model, 'logMutators') ? $model->logMutators() : [];

        foreach ($attributes as $attribute) {
            $old = $rawChanges[$attribute]['old'];
            $new = $rawChanges[$attribute]['new'];

            $mutator = $custom[$attribute] ?? null;

            $transitionType = match (true) {
                is_null($old) && $new !== null => 'added',
                ! is_null($old) && $new !== null && $old !== $new => 'changed',
                ! is_null($old) && $new === null => 'unassigned',
                default => null,
            };

            if ($transitionType === null) {
                continue;
            }

            $computed = null;

            if ($mutator && is_callable($mutator)) {
                try {
                    $computed = $mutator($model, $old, $new, $transitionType);
                } catch (Throwable $e) {
                    $computed = "(Exception: {$e->getMessage()})";
                }
            }

            $format = fn ($value) => match (true) {
                $value instanceof DateTimeInterface => $value->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                is_string($value) && $this->isJsonString($value) => $value,
                default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            };

            $formattedOld = $format($old);
            $formattedNew = $format($new);
            $formattedComputed = $format($computed);

            $computedSuffix = $computed !== null && $formattedComputed !== $formattedNew
                ? " ({$formattedComputed})"
                : '';

            $message = match ($transitionType) {
                'added' => "{$prefix}Attribute [{$attribute}] was assigned: {$formattedNew}{$computedSuffix}",
                'changed' => "{$prefix}Attribute [{$attribute}] changed from {$formattedOld} to {$formattedNew}{$computedSuffix}",
                'unassigned' => "{$prefix}Attribute [{$attribute}] was unassigned (was {$formattedOld}){$computedSuffix}",
            };

            DB::table('application_logs')->insert([
                'event' => $message,
                'block_uuid' => $blockUuid,
                'loggable_type' => get_class($model),
                'loggable_id' => $model->getKey() ?? 0, // fallback to 0 if null
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function isJsonString(string $value): bool
    {
        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }
}
