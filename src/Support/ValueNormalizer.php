<?php

declare(strict_types=1);

namespace Martingalian\Core\Support;

use Carbon\Carbon;

/**
 * Centralized value comparison for detecting semantic equality.
 *
 * Handles type coercion for numeric values and JSON structures,
 * while preserving strict comparison for booleans and strings.
 *
 * Used by ApplicationLogObserver to avoid false positive change logs.
 */
final class ValueNormalizer
{
    /**
     * Check if two values are semantically equal.
     *
     * Normalizes numeric values and JSON structures for comparison,
     * while keeping boolean and string comparisons strict.
     *
     * @param  mixed  $oldValue  The original value (raw from database)
     * @param  mixed  $newValue  The new value (raw from database)
     * @return bool True if values are semantically equal
     */
    public static function areEqual(mixed $oldValue, mixed $newValue): bool
    {
        // Exact match? Done.
        if ($oldValue === $newValue) {
            return true;
        }

        // Both null? Equal
        if ($oldValue === null && $newValue === null) {
            return true;
        }

        // One null, one not? Different
        if ($oldValue === null || $newValue === null) {
            return false;
        }

        // STRATEGY 1: Both numeric? Compare as floats
        // Handles: "5.00000000" vs 5, "0.00001000" vs 0.00001
        if (is_numeric($oldValue) && is_numeric($newValue)) {
            return (float) $oldValue === (float) $newValue;
        }

        // STRATEGY 2: Both JSON-like? Normalize and compare
        // Handles: {"a":1,"b":2} vs {"b":2,"a":1}
        if (self::isJsonLike($oldValue) && self::isJsonLike($newValue)) {
            return self::normalizeJson($oldValue) === self::normalizeJson($newValue);
        }

        // STRATEGY 3: Both Carbon instances? Compare timestamps
        if ($oldValue instanceof Carbon && $newValue instanceof Carbon) {
            return $oldValue->equalTo($newValue);
        }

        // Fallback: Strict comparison (different types or non-comparable)
        return false;
    }

    /**
     * Check if a value is JSON-like (array or valid JSON string).
     */
    protected static function isJsonLike(mixed $value): bool
    {
        if (is_array($value)) {
            return true;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
                json_decode($value);

                return json_last_error() === JSON_ERROR_NONE;
            }
        }

        return false;
    }

    /**
     * Normalize JSON value to sorted array for comparison.
     */
    protected static function normalizeJson(mixed $value): array
    {
        $array = is_array($value) ? $value : json_decode($value, true);

        return self::sortArrayRecursively($array);
    }

    /**
     * Recursively sort array by keys for deterministic comparison.
     */
    protected static function sortArrayRecursively(array $array): array
    {
        ksort($array);

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = self::sortArrayRecursively($value);
            }
        }

        return $array;
    }
}
