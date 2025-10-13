<?php

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Position;

class UpdateTokenLeverageRatioJob extends BaseApiableJob
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
        $this->position->apiUpdateLeverageRatio($this->position->leverage);

        return [
            'message' => "Token {$this->position->parsed_trading_pair} leverage ratio updated to {$this->position->leverage}",
            'attributes' => format_model_attributes($this->position),
        ];
    }
}
