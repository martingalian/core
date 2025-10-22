<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\User;
use Throwable;

final class VerifyPositionResidualAmountJob extends BaseQueueableJob
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
        // Fetch the latest account-positions snapshot from the owning account.
        $positions = ApiSnapshot::getFrom($this->position->account, 'account-positions');

        // Validate that the snapshot contains data for the current trading pair.
        if (is_array($positions) && array_key_exists($this->position->parsed_trading_pair, $positions)) {
            $amount = $positions[$this->position->parsed_trading_pair]['positionAmt'];

            $this->position->logApplicationEvent(
                "Residual amount present - Qty: {$amount}",
                self::class,
                __FUNCTION__
            );

            User::notifyAdminsViaPushover(
                "Position {$this->position->parsed_trading_pair} with residual amount (Qty:{$amount}). Please close position MANUALLY on exchange",
                "Position {$this->position->parsed_trading_pair} with residual amount",
                'nidavellir_warnings'
            );

            return [
                'message' => "Position {$this->position->parsed_trading_pair} with residual amount detected. Qty: {$amount}",
            ];
        }

        return [
            'message' => "Position {$this->position->parsed_trading_pair} without residual amount detected",
        ];
    }

    public function resolveException(Throwable $e)
    {
        User::notifyAdminsViaPushover(
            "[{$this->position->id}] Position {$this->position->parsed_trading_pair} historical data delete error - {$e->getMessage()}",
            '['.class_basename(self::class).'] - Error',
            'nidavellir_errors'
        );
    }
}
