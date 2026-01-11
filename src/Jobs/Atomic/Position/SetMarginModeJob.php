<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Atomic\Position;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Position;

/**
 * SetMarginModeJob (Atomic)
 *
 * Sets the margin mode (isolated/crossed) for a position's symbol on the exchange.
 * The margin mode is read from the account's margin_mode setting.
 *
 * Exchange-specific handling:
 * - Binance: ISOLATED/CROSSED (uppercase)
 * - Bybit: tradeMode 1 (isolated) / 0 (cross)
 * - KuCoin: ISOLATED/CROSS (note: CROSS not CROSSED)
 * - BitGet: isolated/crossed (lowercase)
 *
 * Note: This job is NOT used for Kraken. Kraken uses SetLeveragePreferencesJob instead
 * because Kraken combines margin mode + leverage in a single API call.
 */
class SetMarginModeJob extends BaseApiableJob
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
        $marginMode = $this->position->account->margin_mode;
        $tradingPair = $this->position->exchangeSymbol->parsed_trading_pair;

        $apiResponse = $this->position->apiUpdateMarginType();

        return [
            'position_id' => $this->position->id,
            'trading_pair' => $tradingPair,
            'margin_mode' => $marginMode,
            'message' => "Margin mode set to {$marginMode} for {$tradingPair}",
            'api_response' => $apiResponse->result,
        ];
    }
}
