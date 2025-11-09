<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Illuminate\Database\Eloquent\Model;
use Martingalian\Core\Concerns\HasModelCache;

abstract class BaseModel extends Model
{
    use HasModelCache;

    // Internal only; not persisted
    protected array $attributeChangesCache = [];

    final public function updateSaving(array $attributes, array $options = []): bool
    {
        $this->fill($attributes);

        return $this->save($options);
    }

    final public function cacheChangesForCreate(): void
    {
        $this->attributeChangesCache = [];

        foreach ($this->getAttributes() as $attr => $value) {
            $this->attributeChangesCache[$attr] = [
                'old' => null,
                'new' => $value,
            ];
        }
    }

    final public function cacheChangesForUpdate(): void
    {
        $this->attributeChangesCache = [];

        foreach ($this->getDirty() as $attr => $newValue) {
            $this->attributeChangesCache[$attr] = [
                'old' => $this->getRawOriginal($attr),
                'new' => $newValue,
            ];
        }
    }

    final public function getCachedAttributeChanges(): array
    {
        return $this->attributeChangesCache;
    }

    final public function clearCachedChanges(): void
    {
        $this->attributeChangesCache = [];
    }

    final public function cacheAttributeChanges(): void
    {
        if (! $this->exists) {
            $this->cacheChangesForCreate();
        } else {
            $this->cacheChangesForUpdate();
        }
    }

    final public function clearCachedAttributeChanges(): void
    {
        $this->attributeChangesCache = [];
    }

    final public function updateIfNotSet(string $attribute, mixed $value, ?callable $callable = null): bool
    {
        if (! $this->exists || $this->getKey() === null) {
            return false;
        }

        if (is_null($this->getAttribute($attribute))) {
            $this->setAttribute($attribute, $value);

            $dirty = $this->isDirty($attribute);
            $updated = $dirty ? $this->save() : false;

            if ($dirty && $callable) {
                $callable($this);
            }

            return $updated;
        }

        return false;
    }

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->cacheChangesForCreate());
        static::updating(fn (self $model) => $model->cacheChangesForUpdate());

        static::saving(fn (self $model) => $model->cacheAttributeChanges());
        static::saved(fn (self $model) => $model->clearCachedAttributeChanges());
    }
}
