<?php

declare(strict_types=1);

namespace Martingalian\Core\Martingalian;

use Martingalian\Core\Martingalian\Concerns\DetectsStalePrices;
use Martingalian\Core\Martingalian\Concerns\HasTradingGuards;
use Martingalian\Core\Models\Account;

/**
 * Martingalian — Trading algorithm support class.
 */
final class Martingalian
{
    use DetectsStalePrices;
    use HasTradingGuards;

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
}
