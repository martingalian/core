<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ApiSystem;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Jobs\Models\ExchangeSymbol\CalculateBtcCorrelationJob;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Step;

/**
 * TriggerCorrelationCalculationsJob
 *
 * Creates correlation calculation jobs for all USDT symbols on a specific exchange.
 * Runs after exchange sync is complete (SyncMarketData, SyncLeverageBrackets, CheckPriceSpike).
 */
final class TriggerCorrelationCalculationsJob extends BaseQueueableJob
{
    public int $apiSystemId;

    public function __construct(int $apiSystemId)
    {
        $this->apiSystemId = $apiSystemId;
    }

    public function relatable()
    {
        return ApiSystem::find($this->apiSystemId);
    }

    public function compute()
    {
        if (! config('martingalian.correlation.enabled')) {
            return ['skipped' => true, 'reason' => 'Correlation disabled'];
        }

        $apiSystem = ApiSystem::findOrFail($this->apiSystemId);

        $symbolsCount = 0;

        // Create correlation job for each USDT symbol on this exchange
        // All jobs run in parallel (same block_uuid and index as this job)
        ExchangeSymbol::query()
            ->where('api_system_id', $this->apiSystemId)
            ->where('quote_id', 1) // Only USDT pairs
            ->each(function ($exchangeSymbol) use (&$symbolsCount): void {
                Step::query()->create([
                    'class' => CalculateBtcCorrelationJob::class,
                    'block_uuid' => $this->step->block_uuid,
                    'index' => $this->step->index + 1, // Next index
                    'arguments' => [
                        'exchangeSymbolId' => $exchangeSymbol->id,
                    ],
                ]);
                $symbolsCount++;
            });

        return [
            'api_system' => $apiSystem->canonical,
            'correlation_jobs_created' => $symbolsCount,
        ];
    }
}
