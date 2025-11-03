<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Support\Throttler;
use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Jobs\Models\Order\PlaceMarketOrderJob;
use Martingalian\Core\Jobs\Models\Order\PlaceProfitOrderJob;
use Martingalian\Core\Jobs\Models\Position\UpdatePositionLeverageJob;
use Martingalian\Core\Jobs\Models\Position\UpdatePositionMarginJob;
use Martingalian\Core\Jobs\Models\Position\UpdatePositionStatusJob;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Step;
use Throwable;

/**
 * Lifecycle that will dispatch pending positions that was previously created.
 * Goes through several core jobs to guarantee data integrity and
 * validation on all trading configuration aspects.
 * Accepts positions from different accounts.
 */
final class DispatchPositionJob extends BaseQueueableJob
{
    public Position $position;

    public ?ExchangeSymbol $exchangeSymbol = null;

    public function __construct(int $positionId, ?int $exchangeSymbolId = null)
    {
        $this->position = Position::findOrFail($positionId);

        if ($exchangeSymbolId) {
            $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
        }
    }

    public function startOrFail()
    {
        return ! is_null($this->position->account_id);
    }

    public function compute()
    {
        $uuid = Str::uuid()->toString();

        // Update position status to opening (so it doesn't get caught by watchers).
        $this->position->updateToOpening();

        // Received an exchange symbol? Then update it.
        if ($this->exchangeSymbol) {
            $this->position->updateSaving(['exchange_symbol_id' => $this->exchangeSymbol->id]);
        }

        // No exchange symbol assigned? Try to get one.
        if (! $this->position->exchangeSymbol) {
            $bestExchangeSymbol = $this->position->getBestExchangeSymbol();
            if (! $bestExchangeSymbol) {
                // No token found for this position. Cancel position.
                $this->position->updateToCancelled('Position cancelled because no exchange symbol found for this position');

                return ['result' => 'Cancelling position creation because there was no exchange symbol available'];
            }

            // Update position with this best exchange symbol.
            $this->position->updateSaving([
                'exchange_symbol_id' => $bestExchangeSymbol->id,
            ]);
        }

        $this->position->refresh();

        // Lets update remaining data if not present.
        $this->position->updateIfNotSet(
            'total_limit_orders',
            $this->position->exchangeSymbol->total_limit_orders
        );

        $this->position->updateIfNotSet(
            'indicators_values',
            $this->position->exchangeSymbol->indicators_values,
        );

        $this->position->updateIfNotSet(
            'indicators_timeframe',
            $this->position->exchangeSymbol->indicators_timeframe,
        );

        $this->position->updateIfNotSet(
            'profit_percentage',
            $this->position->account->profit_percentage,
        );

        $this->position->updateIfNotSet(
            'direction',
            $this->position->exchangeSymbol->direction,
        );

        // Here we go.
        $this->position->opened_at = now();

        $i = 1;

        /**
         * Another check to see if the trading pair is opened. If it is, for
         * some reason, we will abort the position creation.
         */
        Step::create([
            'class' => VerifyIfTradingPairIsOpenedJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        Step::create([
            'class' => ContinueIfTradingPairIsNotOpenJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        /**
         * Lets calculate the position margin, and update it on the position.
         * Margin is based on being overriden on the account, or a percentage
         * given from the account trade configuration.
         */
        Step::create([
            'class' => UpdatePositionMarginJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        return [
            'message' => "Position {$this->position->parsed_trading_pair} dispatched for lifecycle.",
            'attributes' => format_model_attributes($this->position),
        ];

        /**
         * Time to calculate the maximum possible leverage. Some symbols
         * or balances might not be able to use the maximum leverage
         * configured on the trade configuration (or account override).
         */
        Step::create([
            'class' => UpdatePositionLeverageJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        /**
         * Lets verify if the current market order can be opened. Sometimes
         * the value is so small that we can't open the market order.
         */
        Step::create([
            'class' => VerifyOrderNotionalForMarketOrderJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        /**
         * Things are going well, now it's time to start changing the token
         * configuration on the exchange. Starting by the token leverage.
         */
        Step::create([
            'class' => UpdateTokenLeverageRatioJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        /**
         * And then the margin type to crossed. Later we can skip this and
         * keep the margin type defined on the token.
         */
        Step::create([
            'class' => UpdatePositionMarginTypeToCrossedJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        Step::create([
            'class' => PlaceMarketOrderJob::class,
            'queue' => 'orders',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        Step::create([
            'class' => PlaceProfitOrderJob::class,
            'queue' => 'orders',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        $childUuid = Str::uuid()->toString();

        Step::create([
            'class' => CreateAndPlaceLimitOrdersJob::class,
            'queue' => 'orders',
            'block_uuid' => $uuid,
            'child_block_uuid' => $childUuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        Step::create([
            'class' => UpdatePositionStatusJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => 'active',
            ],
        ]);

        /**
         * Final step, is to validate the position (watcher).
         */
        Step::create([
            'class' => ValidatePositionJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        Step::create([
            'class' => CancelPositionJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'type' => 'resolve-exception',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        return [
            'message' => "Position {$this->position->parsed_trading_pair} dispatched for lifecycle.",
            'attributes' => format_model_attributes($this->position),
        ];
    }

    public function resolveException(Throwable $e)
    {
        Throttler::using(NotificationService::class)
            ->withCanonical('dispatch_position')
            ->execute(function () {
                NotificationService::sendToAdmin(
                    message: "[{$this->position->id}] Position {$this->position->parsed_trading_pair} lifecycle error - ".ExceptionParser::with($e)->friendlyMessage(),
                    title: '['.class_basename(self::class).'] - Error',
                    deliveryGroup: 'exceptions'
                );
            });

        $this->position->updateSaving([
            'error_message' => ExceptionParser::with($e)->friendlyMessage(),
        ]);
    }
}
