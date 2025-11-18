<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Jobs\Models\Account\QueryPositionsJob;
use Martingalian\Core\Jobs\Models\Position\CalculateWAPAndModifyProfitOrderJob;
use Martingalian\Core\Jobs\Models\Position\UpdatePositionStatusJob;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Support\NotificationService;
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
            'queue' => 'default',
            'block_uuid' => $this->uuid(),
            'index' => 1,
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => 'waping',
            ],
        ]);

        Step::create([
            'class' => QueryPositionsJob::class,
            'queue' => 'default',
            'block_uuid' => $this->uuid(),
            'index' => 2,
            'arguments' => [
                'accountId' => $this->position->account->id,
            ],
        ]);

        Step::create([
            'class' => CalculateWAPAndModifyProfitOrderJob::class,
            'queue' => 'default',
            'block_uuid' => $this->uuid(),
            'index' => 3,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        Step::create([
            'class' => UpdatePositionStatusJob::class,
            'queue' => 'default',
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
        NotificationService::send(
            user: Martingalian::admin(),
            canonical: 'apply_wap',
            referenceData: [
                'position_id' => $this->position->id,
                'trading_pair' => $this->position->parsed_trading_pair,
                'job_class' => class_basename(self::class),
                'error_message' => ExceptionParser::with($e)->friendlyMessage(),
            ],
            cacheKey: "apply_wap:{$this->position->id}"
        );

        $this->position->updateSaving([
            'error_message' => ExceptionParser::with($e)->friendlyMessage(),
        ]);
    }
}
