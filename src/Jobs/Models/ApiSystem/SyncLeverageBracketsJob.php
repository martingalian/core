<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ApiSystem;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Jobs\Models\ExchangeSymbol\Binance\SyncLeverageBracketsJob as BinanceSyncLeverageBracketsJob;
use Martingalian\Core\Jobs\Models\ExchangeSymbol\Bybit\SyncLeverageBracketsJob as BybitSyncLeverageBracketsJob;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Step;

/**
 * SyncLeverageBracketsJob - Parent Orchestrator
 *
 * Routes to exchange-specific child jobs:
 * - Binance: Dispatches 1 child job (fetches all symbols at once)
 * - Bybit: Dispatches N child jobs (one per exchange symbol)
 */
final class SyncLeverageBracketsJob extends BaseApiableJob
{
    public ApiSystem $apiSystem;

    public function __construct(int $apiSystemId)
    {
        // Load the API system instance by ID.
        $this->apiSystem = ApiSystem::findOrFail($apiSystemId);
    }

    public function relatable()
    {
        // Allow job context/logs to link to the ApiSystem.
        return $this->apiSystem;
    }

    public function assignExceptionHandler()
    {
        $canonical = $this->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)->withAccount(Account::admin($canonical));
    }

    public function startOrFail()
    {
        // Only run for exchange-type systems
        return $this->apiSystem->is_exchange;
    }

    public function computeApiable()
    {
        $canonical = $this->apiSystem->canonical;

        // Route to exchange-specific child job
        if ($canonical === 'binance') {
            return $this->dispatchBinanceChildJob();
        }

        if ($canonical === 'bybit') {
            return $this->dispatchBybitChildJobs();
        }

        // Unsupported exchange
        return [
            'error' => "Unsupported exchange: {$canonical}",
        ];
    }

    /**
     * Dispatch single Binance child job.
     * Binance fetches all leverage brackets in one API call.
     */
    public function dispatchBinanceChildJob(): array
    {
        // Get or generate child block UUID (uses BaseQueueableJob::uuid())
        $childBlockUuid = $this->uuid();

        // Set child_block_uuid on the current step so it waits for child
        $this->step->update(['child_block_uuid' => $childBlockUuid]);

        Step::query()->create([
            'class' => BinanceSyncLeverageBracketsJob::class,
            'queue' => 'cronjobs',
            'block_uuid' => $childBlockUuid,
            'index' => 1,
            'arguments' => [
                'apiSystemId' => $this->apiSystem->id,
            ],
        ]);

        return [
            'dispatched' => 1,
            'child_block_uuid' => $childBlockUuid,
        ];
    }

    /**
     * Dispatch child jobs for each Bybit exchange symbol.
     * Each child job queries leverage brackets for one specific symbol.
     * Jobs run in parallel (no index) since they are independent.
     */
    public function dispatchBybitChildJobs(): array
    {
        // Get all exchange symbols for Bybit
        $exchangeSymbols = ExchangeSymbol::query()
            ->where('api_system_id', $this->apiSystem->id)
            ->get();

        if ($exchangeSymbols->isEmpty()) {
            return ['dispatched' => 0];
        }

        // Get or generate child block UUID (uses BaseQueueableJob::uuid())
        $childBlockUuid = $this->uuid();

        // Set child_block_uuid on the current step so it waits for children
        $this->step->update(['child_block_uuid' => $childBlockUuid]);

        foreach ($exchangeSymbols as $exchangeSymbol) {
            Step::query()->create([
                'class' => BybitSyncLeverageBracketsJob::class,
                'queue' => 'cronjobs',
                'block_uuid' => $childBlockUuid,
                // No index - allows parallel execution of all leverage bracket syncs
                'arguments' => [
                    'exchangeSymbolId' => $exchangeSymbol->id,
                ],
            ]);
        }

        return [
            'dispatched' => $exchangeSymbols->count(),
            'child_block_uuid' => $childBlockUuid,
        ];
    }
}
