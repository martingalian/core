<?php

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Position;

class UpdatePositionMarginTypeToCrossedJob extends BaseApiableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make($this->position->account->apiSystem->canonical)->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function computeApiable()
    {
        $this->position->apiUpdateMarginTypeToCrossed();

        return [
            'message' => "Token {$this->position->parsed_trading_pair} margin type updated to CROSSED",
            'attributes' => format_model_attributes($this->position),
        ];
    }
}
