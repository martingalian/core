<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Jobs\Models\ExchangeSymbol\RemoveIndicatorDataJob;
use Martingalian\Core\Jobs\Models\Indicator\QueryIndicatorJob;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Models\User;
use Throwable;

/**
 * Confirms price alignments with indicator directions for exchange symbols.
 * Scoped to a specific API system (exchange) for parallel processing.
 */
final class ConfirmPriceAlignmentsJob extends BaseQueueableJob
{
    public ExchangeSymbol $exchangeSymbolBeingComputed;

    protected ?int $apiSystemId;

    public function __construct(?int $apiSystemId = null)
    {
        $this->apiSystemId = $apiSystemId;
    }

    public function compute()
    {
        $query = ExchangeSymbol::query()->whereNotNull('direction');

        // Scope to specific API system if provided
        if ($this->apiSystemId !== null) {
            $query->where('api_system_id', $this->apiSystemId);
        }

        $query->each(function (ExchangeSymbol $exchangeSymbol) {
            $uuid = Str::uuid()->toString();

            $this->exchangeSymbolBeingComputed = $exchangeSymbol;

            /**
             * First obtain the query indicator data for this exchange symbol
             * on the exact same timeframe as the indicator conclusion.
             */
            Step::create([
                'class' => QueryIndicatorJob::class,
                'queue' => 'indicators',
                'block_uuid' => $uuid,
                'index' => 1,
                'arguments' => [
                    'exchangeSymbolId' => $exchangeSymbol->id,
                    'indicatorId' => Indicator::firstWhere('canonical', 'candle-comparison')->id,
                    'parameters' => ['backtrack' => 0, 'interval' => $exchangeSymbol->indicators_timeframe],
                ],
            ]);

            /**
             * Now, verify if the indicator candle data is aligned with the
             * price fluctuation.
             */
            Step::create([
                'class' => ConfirmPriceAlignmentWithDirectionJob::class,
                'queue' => 'indicators',
                'block_uuid' => $uuid,
                'index' => 2,
                'arguments' => [
                    'exchangeSymbolId' => $exchangeSymbol->id,
                ],
            ]);

            Step::create([
                'class' => RemoveIndicatorDataJob::class,
                'queue' => 'indicators',
                'block_uuid' => $uuid,
                'type' => 'resolve-exception',
                'arguments' => [
                    'exchangeSymbolId' => $exchangeSymbol->id,
                ],
            ]);
        });
    }

    public function resolveException(Throwable $e)
    {
        User::notifyAdminsViaPushover(
            "[{$this->exchangeSymbolBeingComputed->id}] - ExchangeSymbol price confirmation lifecycle error - ".ExceptionParser::with($e)->friendlyMessage(),
            "[S:{$this->step->id}] - ".class_basename(self::class).' - Error',
            'nidavellir_errors'
        );
    }
}
