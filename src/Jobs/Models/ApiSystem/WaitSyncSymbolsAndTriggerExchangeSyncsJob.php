<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ApiSystem;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols\ConcludeIndicatorsJob;
use Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols\ConfirmPriceAlignmentsJob;
use Martingalian\Core\Jobs\Models\ExchangeSymbol\CheckPriceSpikeAndCooldownJob;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Models\StepsDispatcher;

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

        // Create parallel execution blocks for each exchange
        ApiSystem::exchange()->each(function ($exchange) use (&$exchangesTriggered): void {
            // Create a unique UUID for this exchange's job chain
            $exchangeUuid = Str::uuid()->toString();

            // Create a unique child UUID to be used later.
            $childUuid = Str::uuid()->toString();

            // Each exchange starts at index 1 (index 0 doesn't work with StepDispatcher)
            $index = 1;

            // Step 1: Sync market data (exchange symbols, tick sizes, etc.)
            Step::query()->create([
                'class' => SyncMarketDataJob::class,
                'queue' => 'cronjobs',
                'block_uuid' => $exchangeUuid,
                'index' => $index++,
                'group' => $group,
                'arguments' => [
                    'apiSystemId' => $exchange->id,
                ],
            ]);

            // Step 2: Sync leverage brackets
            Step::query()->create([
                'class' => SyncLeverageBracketsJob::class,
                'queue' => 'cronjobs',
                'block_uuid' => $exchangeUuid,
                'index' => $index++,
                'group' => $group,
                'arguments' => [
                    'apiSystemId' => $exchange->id,
                ],
            ]);

            // Step 3: Check price spikes and apply cooldowns (scoped to this exchange)
            Step::query()->create([
                'class' => CheckPriceSpikeAndCooldownJob::class,
                'queue' => 'indicators',
                'block_uuid' => $exchangeUuid,
                'index' => $index++,
                'group' => $group,
                'arguments' => [
                    'apiSystemId' => $exchange->id,
                ],
            ]);

            // Step 4: Conclude indicators for exchange symbols (scoped to this exchange)
            Step::query()->create([
                'class' => ConcludeIndicatorsJob::class,
                'queue' => 'indicators',
                'block_uuid' => $exchangeUuid,
                'child_block_uuid' => $childUuid,
                'index' => $index++,
                'group' => $group,
                'arguments' => [
                    'apiSystemId' => $exchange->id,
                ],
            ]);

            // Step 5: Confirm price alignments with directions (scoped to this exchange)
            Step::query()->create([
                'class' => ConfirmPriceAlignmentsJob::class,
                'queue' => 'indicators',
                'block_uuid' => $exchangeUuid,
                'index' => $index++,
                'group' => $group,
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
