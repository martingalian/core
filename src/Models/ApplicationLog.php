<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApplicationLog extends BaseModel
{
    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function record(string $event, Model $loggable, ?string $sourceClass = null, ?string $sourceMethod = null, ?string $blockUuid = null): self
    {
        // If sourceClass is provided, we get the real class name without the namespace
        if ($sourceClass) {
            $sourceClass = class_basename($sourceClass);
        }

        // Ensure sourceMethod is only the method name, or just 'closure' if it's a closure
        if ($sourceMethod) {
            // Check if it's a closure and simplify the method name if necessary
            if (strpos($sourceMethod, '{closure}') !== false) {
                $sourceMethod = '{closure}';
            } else {
                $sourceMethod = class_basename($sourceMethod);
            }
        }

        // Combine the sourceClass and sourceMethod for the prefix
        $prefix = '';
        if ($sourceClass || $sourceMethod) {
            $prefix = '['.$sourceClass.($sourceMethod ? '.'.$sourceMethod : '').'] - ';
        }

        // Prefix the event with the class and method, if available
        $event = $prefix.$event;

        // Insert the log into the database without saving source_class anymore
        return self::query()->create([
            'event' => mb_substr($event, 0, 5000),
            'loggable_id' => $loggable->getKey(),
            'loggable_type' => $loggable->getMorphClass(),
            'block_uuid' => $blockUuid ?? (string) \Str::uuid(),
        ]);
    }
}
