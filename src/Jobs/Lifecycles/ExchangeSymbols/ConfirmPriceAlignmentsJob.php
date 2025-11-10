<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols;

use Exception;
use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Jobs\Models\Indicator\QueryIndicatorsByChunkJob;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Support\ApiExceptionHandlers\TaapiExceptionHandler;
use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Support\Throttler;
use Throwable;

/**
 * Confirms price alignments with indicator directions for exchange symbols.
 * Scoped to a specific API system (exchange) for parallel processing.
 *
 * Optimization:
 * - Batches symbols into chunks for efficient bulk API queries
 * - Creates separate verification jobs for each symbol after bulk query completes
 * - Reduces API calls from N (one per symbol) to ceil(N / chunk_size)
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

        $exchangeSymbols = $query->get();

        if ($exchangeSymbols->isEmpty()) {
            return ['message' => 'No exchange symbols with direction found'];
        }

        // Get indicator once
        $indicator = Indicator::firstWhere('canonical', 'candle-comparison');

        if (! $indicator) {
            throw new Exception('Indicator "candle-comparison" not found');
        }

        // Calculate chunk size based on Taapi rate limits
        // Each symbol = 1 calculation, max 20 calculations per request
        $exceptionHandler = new TaapiExceptionHandler;
        $maxCalculations = $exceptionHandler->getMaxCalculationsPerRequest();
        $chunkSize = $maxCalculations; // 20 symbols per chunk

        // Create batched query jobs followed by verification jobs
        // All in the same block_uuid chain to ensure sequential execution:
        // 1. Batch queries run first (populate indicator_histories)
        // 2. Verifications run after (read from indicator_histories)
        $chunks = $exchangeSymbols->chunk($chunkSize);
        $batchUuid = Str::uuid()->toString();
        $index = 1;
        $batchJobsCount = 0;

        foreach ($chunks as $chunk) {
            // Group symbols by timeframe to batch efficiently
            $byTimeframe = $chunk->groupBy('indicators_timeframe');

            foreach ($byTimeframe as $timeframe => $symbolsInTimeframe) {
                $exchangeSymbolIds = $symbolsInTimeframe->pluck('id')->toArray();

                // Create bulk query job for this chunk
                Step::create([
                    'class' => QueryIndicatorsByChunkJob::class,
                    'queue' => 'default',
                    'block_uuid' => $batchUuid,
                    'index' => $index++,
                    'arguments' => [
                        'exchangeSymbolIds' => $exchangeSymbolIds,
                        'indicatorId' => $indicator->id,
                        'parameters' => [
                            'backtrack' => 0,
                            'interval' => $timeframe,
                            'results' => 2, // Need 2 candles for comparison
                        ],
                    ],
                ]);
                $batchJobsCount++;
            }
        }

        // Now create individual verification jobs for each symbol
        // Same block_uuid but higher index values - they'll run after all batch jobs complete
        // These will read from indicator_histories table
        foreach ($exchangeSymbols as $exchangeSymbol) {
            Step::create([
                'class' => ConfirmPriceAlignmentWithDirectionJob::class,
                'queue' => 'default',
                'block_uuid' => $batchUuid,
                'index' => $index++,
                'arguments' => [
                    'exchangeSymbolId' => $exchangeSymbol->id,
                ],
            ]);
        }

        return [
            'total_symbols' => $exchangeSymbols->count(),
            'batch_jobs_created' => $batchJobsCount,
            'verification_jobs_created' => $exchangeSymbols->count(),
        ];
    }

    public function resolveException(Throwable $e)
    {
        $symbolId = isset($this->exchangeSymbolBeingComputed) ? $this->exchangeSymbolBeingComputed->id : 'unknown';

        Throttler::using(NotificationService::class)
            ->withCanonical('confirm_price_alignments')
            ->execute(function () use ($e, $symbolId) {
                NotificationService::send(
                    user: Martingalian::admin(),
                    message: "[{$symbolId}] - ExchangeSymbol price confirmation lifecycle error - ".ExceptionParser::with($e)->friendlyMessage(),
                    title: "[S:{$this->step->id}] - ".class_basename(self::class).' - Error',
                    deliveryGroup: 'exceptions'
                );
            });
    }
}
