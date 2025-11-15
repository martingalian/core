<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\ExchangeSymbol;

/*
 * CheckSymbolEligibilityJob
 *
 * • Checks if symbol is eligible for trading
 * • Verifies TAAPI data availability
 * • Checks other eligibility criteria
 * • Returns eligibility status and reason
 */
final class CheckSymbolEligibilityJob extends BaseApiableJob
{
    public string $token;

    public ApiSystem $apiSystem;

    public function __construct(string $token, int $apiSystemId)
    {
        $this->token = $token;
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
        // Get previous step (UpsertExchangeSymbolJob) to retrieve exchange_symbol_id
        $previousStep = $this->step->getPrevious()->first();

        if (! $previousStep || ! $previousStep->response) {
            return ['error' => 'Previous job (UpsertExchangeSymbolJob) did not provide response'];
        }

        $exchangeSymbolId = $previousStep->response['exchange_symbol_id'] ?? null;

        if (! $exchangeSymbolId) {
            return ['error' => 'Could not find exchange_symbol_id from previous step'];
        }

        // Load ExchangeSymbol
        $exchangeSymbol = ExchangeSymbol::find($exchangeSymbolId);

        if (! $exchangeSymbol) {
            return ['error' => 'ExchangeSymbol not found: '.$exchangeSymbolId];
        }

        // Skip if already eligible
        if ($exchangeSymbol->is_eligible) {
            return [
                'exchange_symbol_id' => $exchangeSymbolId,
                'is_eligible' => true,
                'eligibility_reason' => $exchangeSymbol->eligibility_reason,
                'message' => 'ExchangeSymbol is already eligible',
            ];
        }

        // TODO: Implement TAAPI eligibility check using $exchangeSymbol
        // - Check if TAAPI has data available for this symbol
        // - Verify other eligibility criteria
        // - Return eligibility status and reason

        return [
            'exchange_symbol_id' => $exchangeSymbolId,
            'is_eligible' => false,
            'eligibility_reason' => 'TAAPI check not yet implemented',
            'message' => 'Eligibility check placeholder',
        ];
    }
}
