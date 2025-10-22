<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ValueObjects;

final class ApiProperties
{
    public array $properties;

    public function __construct(array $properties = [])
    {
        $this->properties = $properties;
    }

    public static function make(array $properties = []): self
    {
        return new self($properties);
    }

    public function set(string $key, mixed $value): self
    {
        data_set($this->properties, $key, $value);

        return $this;
    }

    public function toArray(): array
    {
        return $this->properties;
    }

    public function getOr(string $key, mixed $default = null): mixed
    {
        return data_get($this->properties, $key, $default);
    }

    public function get(string $key): mixed
    {
        return data_get($this->properties, $key);
    }

    public function mergeIntoNew(array $override): self
    {
        $merged = array_replace_recursive($this->properties, $override);

        return new self($merged);
    }
}
