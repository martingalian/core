<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\ApiSystem;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols\UpsertSymbolEligibilityJob;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\Step;

/*
 * GetAllSymbolsFromExchangeJob
 *
 * • Retrieves the complete list of symbols from an exchange
 * • Does NOT process or validate symbols (that's done by subsequent jobs)
 * • Returns raw symbol data for downstream processing
 * • Skips test/synthetic symbols (those with underscores)
 */
final class GetAllSymbolsFromExchangeJob extends BaseApiableJob
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
        // Fetch all symbols from the exchange
        // Note: Mappers already filter out:
        // - Symbols with underscores (test/synthetic symbols)
        // - Non-perpetual contracts (Bybit: only LinearPerpetual)
        $apiResponse = $this->apiSystem->apiQueryMarketData();

        // Build symbolData lookup array keyed by token for child jobs to access
        $symbolsData = [];

        // Dispatch UpsertSymbolEligibilityJob for each symbol
        foreach ($apiResponse->result as $symbolData) {
            $token = $symbolData['pair'];

            // Store symbolData keyed by token
            $symbolsData[$token] = $symbolData;

            Step::create([
                'class' => UpsertSymbolEligibilityJob::class,
                'arguments' => [
                    'token' => $token,
                    'apiSystemId' => $this->apiSystem->id,
                ],
                'block_uuid' => $this->uuid(),
            ]);
        }

        return [
            'symbols_count' => count($symbolsData),
            'symbols_data' => $symbolsData,
            'message' => 'All symbols dispatched for processing',
        ];
    }
}
