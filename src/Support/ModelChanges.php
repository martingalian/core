<?php

namespace Martingalian\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class ModelChanges
{
    protected array $changes = [];

    public static function with(Model $model): self
    {
        return new self($model);
    }

    public function __construct(protected Model $model)
    {
        // 1. use the snapshot taken during `saving`
        if (method_exists($model, 'getCachedAttributeChanges')) {
            $this->changes = $model->getCachedAttributeChanges();
            // free memory immediately
            $model->clearCachedAttributeChanges();
        }

        // 2. last-ditch: compare current vs original (works when attributes
        //    were assigned then save() – but NOT after refresh()).
        if (empty($this->changes)) {
            foreach ($model->getDirty() as $key => $new) {
                $this->changes[$key] = [
                    'old' => $model->getOriginal($key),
                    'new' => $new,
                ];
            }
        }
    }

    /* ------------------- public API ---------------------------------- */

    public function wasChanged(string $key): bool
    {
        return array_key_exists($key, $this->changes);
    }

    public function old(string $key): mixed
    {
        return $this->changes[$key]['old'] ?? null;
    }

    public function new(string $key): mixed
    {
        return $this->changes[$key]['new'] ?? null;
    }

    public function all(): array
    {
        return $this->changes;
    }

    public function only(array $keys): array
    {
        return Arr::only($this->changes, $keys);
    }
}
