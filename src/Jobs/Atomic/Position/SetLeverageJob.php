<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Atomic\Position;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Position;

/**
 * SetLeverageJob (Atomic)
 *
 * Sets the leverage ratio for a position's symbol on the exchange.
 * The leverage is determined by the position's direction:
 * - LONG: uses account->position_leverage_long
 * - SHORT: uses account->position_leverage_short
 *
 * Note: This job is NOT used for Kraken. Kraken uses SetLeveragePreferencesJob instead
 * because Kraken combines margin mode + leverage in a single API call.
 */
class SetLeverageJob extends BaseApiableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make(
            $this->position->account->apiSystem->canonical
        )->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function computeApiable()
    {
        $tradingPair = $this->position->exchangeSymbol->parsed_trading_pair;
        $direction = $this->position->direction;

        // Get leverage based on position direction
        $leverage = match ($direction) {
            'LONG' => $this->position->account->position_leverage_long,
            'SHORT' => $this->position->account->position_leverage_short,
            default => throw new \RuntimeException("Invalid position direction: {$direction}"),
        };

        $apiResponse = $this->position->apiUpdateLeverageRatio($leverage);

        return [
            'position_id' => $this->position->id,
            'trading_pair' => $tradingPair,
            'direction' => $direction,
            'leverage' => $leverage,
            'message' => "Leverage set to {$leverage}x for {$tradingPair} ({$direction})",
            'api_response' => $apiResponse->result,
        ];
    }
}
