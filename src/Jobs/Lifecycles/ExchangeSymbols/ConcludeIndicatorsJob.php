<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Jobs\Models\ExchangeSymbol\AssessIndicatorConclusionJob;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Models\User;
use Throwable;

/**
 * Iterates each exchange symbol for a specific API system, and triggers the
 * assess indicator conclusion jobs. This will trigger other child
 * jobs, to conclude the indicator direction for each exchange
 * symbol.
 *
 * Scoped to a specific API system (exchange) for parallel processing.
 */
final class ConcludeIndicatorsJob extends BaseQueueableJob
{
    public ExchangeSymbol $exchangeSymbol;

    protected ?int $apiSystemId;

    public function __construct(?int $apiSystemId = null)
    {
        $this->apiSystemId = $apiSystemId;
    }

    public function compute()
    {
        $uuid = $this->uuid();

        $query = ExchangeSymbol::query();

        // Scope to specific API system if provided
        if ($this->apiSystemId !== null) {
            $query->where('api_system_id', $this->apiSystemId);
        }

        $query->get()->each(function ($exchangeSymbol) {

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

    public function resolveException(Throwable $e)
    {
        User::notifyAdminsViaPushover(
            '(no related ids on the ConcludeIndicatorsJob) - lifecycle error - '.ExceptionParser::with($e)->friendlyMessage(),
            "[S:{$this->step->id} ES:{$this->exchangeSymbol->id}] ".class_basename(self::class).' - Error',
            'nidavellir_errors'
        );
    }
}
