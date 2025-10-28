<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Jobs\Models\Account\QueryPositionsJob;
use Martingalian\Core\Jobs\Models\Position\CalculateWAPAndModifyProfitOrderJob;
use Martingalian\Core\Jobs\Models\Position\UpdatePositionStatusJob;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Support\NotificationThrottler;
use Throwable;

final class ApplyWAPJob extends BaseQueueableJob
{
    public Position $position;

    public string $by;

    public function __construct(int $positionId, string $by = 'user data stream')
    {
        $this->position = Position::findOrFail($positionId);
        $this->by = $by;
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->position->account->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function compute()
    {
        $this->position->updateSaving([
            'waped_by' => $this->by,
        ]);

        Step::create([
            'class' => UpdatePositionStatusJob::class,
            'queue' => 'positions',
            'block_uuid' => $this->uuid(),
            'index' => 1,
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => 'waping',
            ],
        ]);

        Step::create([
            'class' => QueryPositionsJob::class,
            'queue' => 'positions',
            'block_uuid' => $this->uuid(),
            'index' => 2,
            'arguments' => [
                'accountId' => $this->position->account->id,
            ],
        ]);

        Step::create([
            'class' => CalculateWAPAndModifyProfitOrderJob::class,
            'queue' => 'positions',
            'block_uuid' => $this->uuid(),
            'index' => 3,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        Step::create([
            'class' => UpdatePositionStatusJob::class,
            'queue' => 'positions',
            'block_uuid' => $this->uuid(),
            'index' => 4,
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => 'active',
            ],
        ]);
    }

    public function resolveException(Throwable $e)
    {
        NotificationThrottler::sendToAdmin(
            messageCanonical: 'apply_wap',
            message: "[{$this->position->id}] Position {$this->position->parsed_trading_pair} lifecycle error - ".ExceptionParser::with($e)->friendlyMessage(),
            title: '['.class_basename(self::class).'] - Error',
            deliveryGroup: 'exceptions'
        );

        $this->position->updateSaving([
            'error_message' => ExceptionParser::with($e)->friendlyMessage(),
        ]);
    }
}
