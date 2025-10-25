<?php

declare(strict_types=1);

namespace Martingalian\Core\Enums;

/**
 * Represents the possible conclusions from indicator analysis.
 *
 * Direction indicators return LONG or SHORT.
 * Validation indicators return VALID or INVALID.
 */
enum IndicatorConclusion: string
{
    case LONG = 'LONG';
    case SHORT = 'SHORT';
    case VALID = '1';
    case INVALID = '0';

    /**
     * Check if this conclusion represents a direction (LONG or SHORT).
     */
    public function isDirection(): bool
    {
        return $this === self::LONG || $this === self::SHORT;
    }

    /**
     * Check if this conclusion represents a validation result.
     */
    public function isValidation(): bool
    {
        return $this === self::VALID || $this === self::INVALID;
    }

    /**
     * For validation conclusions, check if it indicates validity.
     */
    public function passes(): bool
    {
        return $this === self::VALID;
    }
}
