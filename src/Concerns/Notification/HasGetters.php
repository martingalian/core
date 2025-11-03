<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\Notification;

trait HasGetters
{
    /**
     * Find an active notification by canonical identifier.
     */
    public static function findByCanonical(string $canonical): ?self
    {
        return self::where('canonical', $canonical)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Check if a notification canonical exists and is active.
     */
    public static function exists(string $canonical): bool
    {
        return self::where('canonical', $canonical)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Get all active notification canonicals as an array.
     *
     * @return array<int, string>
     */
    public static function activeCanonicals(): array
    {
        /** @var array<int, string> */
        return self::where('is_active', true)
            ->pluck('canonical')
            ->toArray();
    }
}
