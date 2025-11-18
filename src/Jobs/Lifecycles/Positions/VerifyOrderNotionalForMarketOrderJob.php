<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Exception;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\NotificationService;
use Throwable;

final class VerifyOrderNotionalForMarketOrderJob extends BaseApiableJob
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
        $exchangeSymbol = $this->position->exchangeSymbol;
        $markPrice = $this->position->exchangeSymbol->apiQueryMarkPrice();

        // Update last mark price on the exchange symbol (just in case).
        $exchangeSymbol->updateSaving(['mark_price' => $markPrice->result['mark_price']]);

        $divider = get_market_order_amount_divider($this->position->total_limit_orders);
        $notional = $this->position->margin * $this->position->leverage / $divider;

        $marketOrderQuantity = $exchangeSymbol->getQuantityForAmount($notional, true);

        $minimumNotional = remove_trailing_zeros($exchangeSymbol->min_notional);

        if ((float) $marketOrderQuantity === 0.0) {
            throw new Exception("Order size ({$notional}) results in unusable quantity (fails minimum notional of {$minimumNotional})");
        }

        $marketOrderNotional = $exchangeSymbol->getAmountForQuantity($marketOrderQuantity);

        if ((float) $marketOrderNotional === 0.0) {
            $marketOrderNotional = remove_trailing_zeros($marketOrderNotional);
            throw new Exception("Market order notional ({$marketOrderNotional}) results in unusable quantity (fails minimum notional of {$minimumNotional})");
        }

        return [
            'message' => "Notional approved. Quantity for market order: {$marketOrderQuantity}, Amount: {$marketOrderNotional}",
            'attributes' => format_model_attributes($this->position),
        ];
    }

    public function resolveException(Throwable $e)
    {
        NotificationService::send(
            user: Martingalian::admin(),
            canonical: 'verify_order_notional_market',
            referenceData: [
                'position_id' => $this->position->id,
                'trading_pair' => $this->position->parsed_trading_pair,
                'job_class' => class_basename(self::class),
                'error_message' => ExceptionParser::with($e)->friendlyMessage(),
            ],
            cacheKey: "verify_order_notional_market:{$this->position->id}"
        );

        $this->position->updateSaving([
            'error_message' => ExceptionParser::with($e)->friendlyMessage(),
        ]);
    }
}
