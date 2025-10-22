<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Position;

use Exception;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\Martingalian;
use Martingalian\Core\Support\Math;

/**
 * UpdatePositionMarginJob
 *
 * Sets the position's market-order margin (quote currency) from the account's
 * side-specific percentage and current portfolio balance.
 *
 * Precedence:
 *  1) If position->margin is already set, keep it (idempotent).
 *  2) Otherwise compute: margin = (side % / 100) * portfolio balance.
 *
 * Notes:
 * - Percentages are already in percent units (e.g. 0.43 = 0.43%).
 * - Uses Martingalian::SCALE for BCMath precision.
 * - Assumes relationships return results.
 */
final class UpdatePositionMarginJob extends BaseQueueableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function compute()
    {
        // Idempotency: if margin already set, keep it.
        if ($this->position->margin !== null) {
            return [
                'message' => "Position margin already set to {$this->position->margin} before",
                'attributes' => format_model_attributes($this->position),
            ];
        }

        $account = $this->position->account;

        // Load latest balance snapshot for the account's portfolio quote.
        $balance = $account->apiSnapshots()
            ->where('canonical', 'account-balance')
            ->firstOr(fn () => throw new Exception('No account balance snapshot to calculate margin'));

        $quote = $account->portfolioQuote->canonical;

        // If the quote is missing on the exchange portfolio, deactivate trading and fail.
        if (! array_key_exists($quote, $balance->api_response)) {
            $account->updateSaving(['can_trade' => false]);

            throw new Exception("Account exception: Portfolio quote ({$quote}) doesn't exist on exchange portfolio.");
        }

        // Obtain side-specific percentage (already in percent units).
        $percentagePct = match ($this->position->direction) {
            'LONG' => $account->market_order_margin_percentage_long,
            'SHORT' => $account->market_order_margin_percentage_short,
            default => throw new Exception("Invalid direction: {$this->position->direction}"),
        };

        // Convert percent → decimal (e.g. 0.43 → 0.0043) and compute margin with BCMath scale.
        $percentDec = Math::div((string) $percentagePct, '100', Martingalian::SCALE);
        $portfolioBalance = (string) $balance->api_response[$quote];
        $calculatedMargin = remove_trailing_zeros(
            Math::mul($percentDec, $portfolioBalance, Martingalian::SCALE)
        );

        // Persist margin on the position.
        $this->position->updateSaving(['margin' => $calculatedMargin]);

        return [
            'message' => "Position Market margin set to {$calculatedMargin} {$quote}",
            'attributes' => format_model_attributes($this->position),
        ];
    }
}
