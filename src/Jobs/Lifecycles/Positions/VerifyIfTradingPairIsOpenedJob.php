<?php

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\Position;

class VerifyIfTradingPairIsOpenedJob extends BaseQueueableJob
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
        if ($this->isTradingPairAlreadyOpened()) {
            return ['opened' => true];
        }

        $positions = ApiSnapshot::getFrom($this->position->account, 'account-positions');

        if (is_array($positions) &&
        array_key_exists($this->position->parsed_trading_pair, $positions)
        ) {
            $all = $positions[$this->position->parsed_trading_pair];

            $match = collect(is_array($all) ? $all : [$all])
                ->contains(function ($p) {
                    if (! isset($p['positionAmt'])) {
                        return false;
                    }

                    return $this->position->direction === 'LONG'
                        ? $p['positionAmt'] > 0
                        : $p['positionAmt'] < 0;
                });

            if ($match) {
                return ['opened' => true];
            }
        }

        return ['opened' => false];
    }

    // Helper method to check if the trading pair is already opened
    protected function isTradingPairAlreadyOpened(): bool
    {
        $account = $this->position->account;

        // Get all active positions for the account excluding the current position
        $activePositions = $account->positions()
            ->active()  // Assuming there's an 'active' scope in the Position model
            ->where('positions.id', '!=', $this->position->id)  // Exclude the current position
            ->where('positions.exchange_symbol_id', $this->position->exchange_symbol_id)  // Check for the same exchange symbol id
            ->get();

        // If there are any active positions with the same exchange symbol, return true
        return $activePositions->isNotEmpty();
    }
}
