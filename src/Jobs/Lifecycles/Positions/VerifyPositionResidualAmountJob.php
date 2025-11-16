<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Support\NotificationThrottler;
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

            NotificationThrottler::using(NotificationService::class)
                ->withCanonical('position_residual_amount_detected')
                ->execute(function () use ($amount) {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: "Position {$this->position->parsed_trading_pair} with residual amount (Qty:{$amount}). Please close position MANUALLY on exchange",
                        title: "Position {$this->position->parsed_trading_pair} with residual amount",
                        canonical: 'position_residual_amount_detected',
                        deliveryGroup: 'exceptions'
                    );
                });

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
        NotificationThrottler::using(NotificationService::class)
            ->withCanonical('position_residual_verification_error')
            ->execute(function () use ($e) {
                NotificationService::send(
                    user: Martingalian::admin(),
                    message: "[{$this->position->id}] Position {$this->position->parsed_trading_pair} residual verification error - {$e->getMessage()}",
                    title: '['.class_basename(self::class).'] - Error',
                    canonical: 'position_residual_verification_error',
                    deliveryGroup: 'exceptions'
                );
            });
    }
}
