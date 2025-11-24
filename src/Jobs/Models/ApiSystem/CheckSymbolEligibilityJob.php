<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ApiSystem;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Indicators\History\CandleIndicator;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\ExchangeSymbol;
use Throwable;

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
        // Use TAAPI exception handler since we're querying TAAPI
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')
            ->withAccount(Account::admin('taapi'));
    }

    public function startOrFail()
    {
        return $this->apiSystem->is_exchange;
    }

    /**
     * Skip this job if the ExchangeSymbol already has TAAPI data.
     * This prevents unnecessary Taapi API calls and throttling.
     *
     * @return bool|null Return false to skip, true to proceed
     */
    public function startOrSkip()
    {
        // Load ExchangeSymbol from previous step's response
        $previousStep = $this->step->getPrevious()->first();

        if (! $previousStep || ! $previousStep->response) {
            return true; // Proceed if no previous step
        }

        $exchangeSymbolId = $previousStep->response['exchange_symbol_id'] ?? null;

        if (! $exchangeSymbolId) {
            return true; // Proceed if no exchange_symbol_id
        }

        $exchangeSymbol = ExchangeSymbol::find($exchangeSymbolId);

        // Skip if TAAPI data already verified (no Taapi call needed, no throttling)
        if ($exchangeSymbol?->has_taapi_data) {
            return false; // Return false = skip the job
        }

        return true; // Proceed to throttle check and eligibility verification
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

        // Skip if TAAPI data already verified
        if ($exchangeSymbol->has_taapi_data) {
            return [
                'exchange_symbol_id' => $exchangeSymbolId,
                'has_taapi_data' => true,
                'message' => 'ExchangeSymbol already has TAAPI data verified',
            ];
        }

        // Check TAAPI indicator data availability using CandleIndicator
        $hasTaapiData = $this->checkTaapiIndicatorData($exchangeSymbol);

        // Update TAAPI data availability status
        $exchangeSymbol->update([
            'has_taapi_data' => $hasTaapiData,
        ]);

        return [
            'exchange_symbol_id' => $exchangeSymbolId,
            'has_taapi_data' => $hasTaapiData,
            'message' => $hasTaapiData ? 'TAAPI data available' : 'TAAPI data not available',
        ];
    }

    private function checkTaapiIndicatorData(ExchangeSymbol $exchangeSymbol): bool
    {
        try {
            // Instantiate CandleIndicator with 1h interval
            $candleIndicator = new CandleIndicator($exchangeSymbol, ['interval' => '1h']);

            // Attempt to fetch candle data from TAAPI
            $data = $candleIndicator->compute();

            // If we got valid data back, TAAPI has data for this symbol
            return ! empty($data);
        } catch (Throwable $e) {
            // If TAAPI throws an exception, it doesn't have data for this symbol
            return false;
        }
    }
}
