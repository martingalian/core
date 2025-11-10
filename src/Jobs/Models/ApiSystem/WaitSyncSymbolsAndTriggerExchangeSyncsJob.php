<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ApiSystem;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Jobs\Models\ExchangeSymbol\CheckPriceSpikeAndCooldownJob;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\Step;

/**
 * WaitSyncSymbolsAndTriggerExchangeSyncsJob
 *
 * Acts as a barrier/wait job between SyncSymbolJob(s) and exchange-specific jobs.
 * Once all symbol syncs are complete, this job triggers parallel execution of
 * exchange sync jobs by creating a separate block_uuid for each exchange.
 *
 * This allows multiple exchanges (e.g., Binance, Bybit) to sync in parallel
 * instead of waiting sequentially.
 */
final class WaitSyncSymbolsAndTriggerExchangeSyncsJob extends BaseQueueableJob
{
    public function relatable()
    {
        return null;
    }

    public function compute()
    {
        $exchangesTriggered = 0;

        // Get the parent step's group to propagate to child exchange chains
        $parentGroup = $this->step->group;

        // Create parallel execution blocks for each exchange
        ApiSystem::exchange()->each(function ($exchange) use (&$exchangesTriggered, $parentGroup): void {
            // Create a unique UUID for this exchange's job chain
            $exchangeUuid = Str::uuid()->toString();

            // Each exchange starts at index 1 (index 0 doesn't work with StepDispatcher)
            $index = 1;

            // Step 1: Sync market data - set group explicitly as first step in new block_uuid chain
            Step::query()->create([
                'class' => SyncMarketDataJob::class,
                'queue' => 'default',
                'block_uuid' => $exchangeUuid,
                'index' => $index++,
                'group' => $parentGroup,
                'arguments' => [
                    'apiSystemId' => $exchange->id,
                ],
            ]);

            // Step 2: Sync leverage brackets - inherits group via StepObserver
            Step::query()->create([
                'class' => SyncLeverageBracketsJob::class,
                'queue' => 'default',
                'block_uuid' => $exchangeUuid,
                'index' => $index++,
                'arguments' => [
                    'apiSystemId' => $exchange->id,
                ],
            ]);

            // Step 3: Check price spikes and apply cooldowns - inherits group via StepObserver
            Step::query()->create([
                'class' => CheckPriceSpikeAndCooldownJob::class,
                'queue' => 'default',
                'block_uuid' => $exchangeUuid,
                'index' => $index++,
                'arguments' => [
                    'apiSystemId' => $exchange->id,
                ],
            ]);

            $exchangesTriggered++;
        });

        return [
            'exchanges_triggered' => $exchangesTriggered,
            'execution_mode' => 'parallel',
        ];
    }
}
