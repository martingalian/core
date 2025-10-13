<?php

namespace Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Jobs\Models\ExchangeSymbol\AssessIndicatorConclusionJob;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Models\User;

/**
 * Iterates each exchange symbol on the database, and triggers the
 * assess indicator conclusion jobs. This will trigger other child
 * jobs, to conclude the indicator direction for each exchange
 * symbol.
 */
class ConcludeIndicatorsJob extends BaseQueueableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public function compute()
    {
        $uuid = $this->uuid();

        ExchangeSymbol::all()->each(function ($exchangeSymbol) {

            $this->exchangeSymbol = $exchangeSymbol;

            Step::create([
                'class' => AssessIndicatorConclusionJob::class,
                'block_uuid' => $this->uuid(),
                'child_block_uuid' => Str::uuid()->toString(),
                'queue' => 'indicators',
                'arguments' => [
                    'exchangeSymbolId' => $exchangeSymbol->id,
                ],
            ]);
        });
    }

    public function resolveException(\Throwable $e)
    {
        User::notifyAdminsViaPushover(
            '(no related ids on the ConcludeIndicatorsJob) - lifecycle error - '.ExceptionParser::with($e)->friendlyMessage(),
            "[S:{$this->step->id} ES:{$this->exchangeSymbol->id}] ".class_basename(static::class).' - Error',
            'nidavellir_errors'
        );
    }
}
