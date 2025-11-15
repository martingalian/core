<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\ApiSystem;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\Step;

/*
 * DiscoverExchangeSymbolsJob
 *
 * • Discovers all available symbols from the exchange
 * • Validates eligibility for each symbol (TAAPI data availability)
 * • Creates per-symbol workflow steps for processing
 * • Updates symbol and exchange_symbol records with eligibility status
 */
final class DiscoverExchangeSymbolsJob extends BaseApiableJob
{
    public ApiSystem $apiSystem;

    public function __construct(int $apiSystemId)
    {
        $this->apiSystem = ApiSystem::findOrFail($apiSystemId);
    }

    public function relatable()
    {
        return $this->apiSystem;
    }

    public function assignExceptionHandler()
    {
        $canonical = $this->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount(Account::admin($canonical));
    }

    public function startOrFail()
    {
        return $this->apiSystem->is_exchange;
    }

    public function computeApiable()
    {
        $childBlockUuid = (string) Str::uuid();

        Step::create([
            'class' => GetAllSymbolsFromExchangeJob::class,
            'arguments' => ['apiSystemId' => $this->apiSystem->id],
            'block_uuid' => $this->uuid(),
            'child_block_uuid' => $childBlockUuid,
        ]);


        Step::create([
            'class' => TriggerCorrelationCalculationsJob::class,
            'arguments' => [
                'apiSystemId' => $this->apiSystem->id,
            ],
            'block_uuid' => $this->uuid(),
            'index' => 2,
        ]);

        return ['message' => 'Symbol discovery workflow initiated'];
    }
}
