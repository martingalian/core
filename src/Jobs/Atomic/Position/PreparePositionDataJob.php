<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Atomic\Position;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\Math;

/**
 * PreparePositionDataJob (Atomic)
 *
 * Populates position data before market order placement.
 * Sets margin (with subscription cap), leverage, profit percentage,
 * indicators, and total limit orders.
 *
 * Must run AFTER token assignment (exchange_symbol_id must be set).
 * Must run BEFORE PlaceMarketOrderJob.
 */
class PreparePositionDataJob extends BaseQueueableJob
{
    public Position $position;

    public bool $hasValidMargin = false;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable()
    {
        return $this->position;
    }

    /**
     * Position must have exchange_symbol_id assigned by token assignment step.
     */
    public function startOrFail(): bool
    {
        return $this->position->exchange_symbol_id !== null;
    }

    public function compute()
    {
        $account = $this->position->account;
        $exchangeSymbol = $this->position->exchangeSymbol;
        $direction = $this->position->direction;

        // 1. Calculate margin with subscription cap
        $margin = $this->calculateMarginWithSubscriptionCap($account);

        // Check if margin is valid (> 0)
        $this->hasValidMargin = Math::gt($margin, '0', 2);

        // Stop gracefully if margin is 0 or negative (no balance available)
        if (! $this->hasValidMargin) {
            return [
                'position_id' => $this->position->id,
                'stopped' => true,
                'reason' => 'Margin is zero or negative - no balance available',
                'calculated_margin' => $margin,
            ];
        }

        // 2. Get leverage based on direction
        $leverage = $direction === 'LONG'
            ? $account->position_leverage_long
            : $account->position_leverage_short;

        // 3. Get profit percentage from account
        $profitPercentage = $account->profit_percentage;

        // 4. Get indicators from exchange symbol
        $indicatorsValues = $exchangeSymbol->indicators_values;
        $indicatorsTimeframe = $exchangeSymbol->indicators_timeframe;

        // 5. Get total limit orders from exchange symbol (fallback to 4)
        $totalLimitOrders = $exchangeSymbol->total_limit_orders ?? 4;

        // Update position with all data
        $this->position->updateSaving([
            'margin' => $margin,
            'leverage' => $leverage,
            'profit_percentage' => $profitPercentage,
            'indicators_values' => $indicatorsValues,
            'indicators_timeframe' => $indicatorsTimeframe,
            'total_limit_orders' => $totalLimitOrders,
            'status' => 'opening',
        ]);

        return [
            'position_id' => $this->position->id,
            'exchange_symbol' => $exchangeSymbol->parsed_trading_pair,
            'direction' => $direction,
            'margin' => $margin,
            'leverage' => $leverage,
            'profit_percentage' => (string) $profitPercentage,
            'indicators_timeframe' => $indicatorsTimeframe,
            'total_limit_orders' => $totalLimitOrders,
            'was_fast_traded' => $this->position->was_fast_traded,
        ];
    }

    /**
     * Calculate margin with subscription cap.
     *
     * Formula: balance × (max_position_percentage / 100)
     * Cap: subscription.max_balance (if not unlimited)
     */
    public function calculateMarginWithSubscriptionCap(Account $account): string
    {
        // Get account balance from ApiSnapshot (stored by VerifyMinAccountBalanceJob)
        $balanceSnapshot = ApiSnapshot::getFrom($account, 'account-balance');
        $balance = $balanceSnapshot['available-balance']
            ?? $account->margin
            ?? '0';

        // Calculate position margin
        $maxPct = $account->max_position_percentage ?? '5.00';
        $margin = bcmul($balance, bcdiv($maxPct, '100', 8), 2);

        // Check subscription cap
        $subscription = $account->user->subscription;
        if ($subscription && ! $subscription->hasUnlimitedBalance()) {
            $maxBalance = $subscription->max_balance;
            if (bccomp($margin, $maxBalance, 2) > 0) {
                $margin = $maxBalance;
            }
        }

        return $margin;
    }

    /**
     * Stop the workflow gracefully if margin is invalid.
     */
    public function complete(): void
    {
        if (! $this->hasValidMargin) {
            $this->stopJob();
        }
    }
}
