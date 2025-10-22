<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Position;

use InvalidArgumentException;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\Martingalian;

final class UpdatePositionLeverageJob extends BaseQueueableJob
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

    /**
     * Computes and sets the position leverage using Martingalian::calculateMaxLeverageForNotional().
     *
     * Rules:
     *  - If leverage already set → no-op.
     *  - Uses the account-side max (per LONG/SHORT) as the target leverage.
     *  - Lets the calculator validate against the symbol’s exchange brackets.
     *  - Final leverage is always ≤ account max and bracket-feasible.
     */
    public function compute()
    {
        // If already set, return early with a small debug payload.
        if (! is_null($this->position->leverage)) {
            return [
                'message' => "Position leverage already set to {$this->position->leverage}",
                'attributes' => format_model_attributes($this->position),
            ];
        }

        if (empty($this->position->exchange_symbol_id)) {
            throw new InvalidArgumentException('exchange_symbol_id is required to compute leverage.');
        }

        // 1) Resolve the account-level cap that we will *target* (LONG/SHORT specific).
        $accountMaxLeverage = match ($this->position->direction) {
            'LONG' => (int) $this->position->account->position_leverage_long,
            'SHORT' => (int) $this->position->account->position_leverage_short,
            default => throw new InvalidArgumentException("Unknown position direction: {$this->position->direction}"),
        };

        if ($accountMaxLeverage < 1) {
            throw new InvalidArgumentException("Account max leverage must be >= 1 (got: {$accountMaxLeverage}).");
        }

        // 2) Ask the bracket-only calculator for the largest feasible leverage
        //    under the symbol's exchange brackets, *capped by* our target (account max).
        //    This method does not apply business caps on its own; passing the target
        //    as $accountMaxLeverage ensures the result never exceeds the account limit.
        $calc = Martingalian::calculateMaxLeverageForNotional(
            margin: $this->position->margin,
            targetLeverage: $accountMaxLeverage,
            exchangeSymbolId: (int) $this->position->exchange_symbol_id
        );

        // 3) Choose the leverage returned by the calculator ('chosen' is already ≤ target and bracket-feasible).
        $chosenLeverage = (int) ($calc['chosen']['leverage'] ?? 1);

        // Extra safety: never exceed account cap even if upstream changes.
        if ($chosenLeverage > $accountMaxLeverage) {
            $chosenLeverage = $accountMaxLeverage;
        }
        if ($chosenLeverage < 1) {
            $chosenLeverage = 1;
        }

        // 4) Persist it.
        $this->position->updateSaving(['leverage' => $chosenLeverage]);

        return [
            'message' => "Position leverage set to {$this->position->leverage}",
            'attributes' => format_model_attributes($this->position),

            // Helpful debug context for dashboards / logs.
            'debug' => [
                'requested' => $calc['requested'] ?? null,     // ['leverage','notional']
                'chosen' => $calc['chosen'] ?? null,        // ['leverage','notional','reason']
                'feasible' => $calc['feasible'] ?? null,      // ['maxLeverage','maxNotional','bracket']
                'accountMaxLeverage' => $accountMaxLeverage,
                'exchangeSymbolId' => (int) $this->position->exchange_symbol_id,
            ],
        ];
    }
}
