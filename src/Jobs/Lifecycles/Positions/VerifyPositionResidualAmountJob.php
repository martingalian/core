<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\NotificationService;
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

            NotificationService::send(
                user: Martingalian::admin(),
                canonical: 'position_residual_amount_detected',
                referenceData: [
                    'position_id' => $this->position->id,
                    'trading_pair' => $this->position->parsed_trading_pair,
                    'amount' => $amount,
                ],
                cacheKey: "position_residual_amount_detected:{$this->position->id}"
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
        NotificationService::send(
            user: Martingalian::admin(),
            canonical: 'position_residual_verification_error',
            referenceData: [
                'position_id' => $this->position->id,
                'trading_pair' => $this->position->parsed_trading_pair,
                'job_class' => class_basename(self::class),
                'error_message' => $e->getMessage(),
            ],
            cacheKey: "position_residual_verification_error:{$this->position->id}"
        );
    }
}
