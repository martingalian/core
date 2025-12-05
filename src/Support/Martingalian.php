<?php

declare(strict_types=1);

namespace Martingalian\Core\Support;

use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Martingalian as MartingalianModel;

/**
 * Martingalian — Trading algorithm support class.
 */
final class Martingalian
{
    public function __construct(
        public Account $account,
    ) {}

    /**
     * Create a new instance with the given account.
     */
    public static function withAccount(Account $account): self
    {
        return new self($account);
    }

    /**
     * Global guard for opening positions.
     * Checks the allow_opening_positions flag from the martingalian singleton.
     */
    public function canOpenPositions(): bool
    {
        $martingalian = MartingalianModel::first();

        if ($martingalian === null) {
            return false;
        }

        // TODO: Add circuit breaker logic using $this->account

        return $martingalian->allow_opening_positions;
    }

    /**
     * Directional guard for opening SHORT positions.
     */
    public function canOpenShorts(): bool
    {
        // TODO: Add business logic using $this->account
        return true;
    }

    /**
     * Directional guard for opening LONG positions.
     */
    public function canOpenLongs(): bool
    {
        // TODO: Add business logic using $this->account
        return true;
    }
}
