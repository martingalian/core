<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Atomic\Position;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Position;

/**
 * SetLeveragePreferencesJob (Atomic) - Kraken Only
 *
 * Sets leverage preferences on Kraken, which combines margin mode + leverage in one API call.
 *
 * Kraken API behavior:
 * - Setting maxLeverage = ISOLATED margin mode with that leverage
 * - Omitting maxLeverage = CROSS margin mode (dynamic leverage based on wallet balance)
 *
 * The margin mode is determined by the account's margin_mode setting:
 * - 'isolated': Sends maxLeverage parameter → ISOLATED margin
 * - 'crossed': Omits maxLeverage parameter → CROSS margin
 *
 * Leverage is determined by position direction:
 * - LONG: uses account->position_leverage_long
 * - SHORT: uses account->position_leverage_short
 */
class SetLeveragePreferencesJob extends BaseApiableJob
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
        $marginMode = $this->position->account->margin_mode;

        // Get leverage based on position direction
        $leverage = match ($direction) {
            'LONG' => $this->position->account->position_leverage_long,
            'SHORT' => $this->position->account->position_leverage_short,
            default => throw new \RuntimeException("Invalid position direction: {$direction}"),
        };

        // Call the combined API method (handles margin mode internally based on account setting)
        $apiResponse = $this->position->apiSetLeveragePreferences($leverage);

        // For CROSS margin mode, Kraken ignores the leverage parameter
        $effectiveLeverage = $marginMode === 'isolated' ? "{$leverage}x" : 'dynamic (cross)';

        return [
            'position_id' => $this->position->id,
            'trading_pair' => $tradingPair,
            'direction' => $direction,
            'margin_mode' => $marginMode,
            'leverage' => $effectiveLeverage,
            'message' => "Leverage preferences set: {$marginMode} margin, {$effectiveLeverage} for {$tradingPair}",
            'api_response' => $apiResponse->result,
        ];
    }
}
