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
     * Skip this job if the ExchangeSymbol is already eligible.
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

        // Skip if already eligible (no Taapi call needed, no throttling)
        if ($exchangeSymbol?->is_eligible) {
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

        // Skip if already eligible
        if ($exchangeSymbol->is_eligible) {
            return [
                'exchange_symbol_id' => $exchangeSymbolId,
                'is_eligible' => true,
                'ineligible_reason' => $exchangeSymbol->ineligible_reason,
                'message' => 'ExchangeSymbol is already eligible',
            ];
        }

        // Check TAAPI indicator data availability using CandleIndicator
        $eligibilityResult = $this->checkTaapiIndicatorData($exchangeSymbol);

        // Update eligibility status
        $exchangeSymbol->update([
            'is_eligible' => $eligibilityResult['is_eligible'],
            'ineligible_reason' => $eligibilityResult['ineligible_reason'],
        ]);

        return [
            'exchange_symbol_id' => $exchangeSymbolId,
            'is_eligible' => $eligibilityResult['is_eligible'],
            'ineligible_reason' => $eligibilityResult['ineligible_reason'],
            'message' => $eligibilityResult['is_eligible'] ? 'Symbol is eligible' : 'Symbol is not eligible',
        ];
    }

    /**
     * @return array{is_eligible: bool, ineligible_reason: string|null}
     */
    private function checkTaapiIndicatorData(ExchangeSymbol $exchangeSymbol): array
    {
        try {
            // Instantiate CandleIndicator with 1h interval
            $candleIndicator = new CandleIndicator($exchangeSymbol, ['interval' => '1h']);

            // Attempt to fetch candle data from TAAPI
            $data = $candleIndicator->compute();

            // If we got valid data back, TAAPI has data for this symbol
            if (! empty($data)) {
                return [
                    'is_eligible' => true,
                    'ineligible_reason' => null,
                ];
            }

            return [
                'is_eligible' => false,
                'ineligible_reason' => 'TAAPI returned empty data',
            ];
        } catch (Throwable $e) {
            // Extract just the relevant error message from TAAPI response
            $errorMessage = $this->extractTaapiError($e->getMessage());

            return [
                'is_eligible' => false,
                'ineligible_reason' => $errorMessage,
            ];
        }
    }

    private function extractTaapiError(string $fullError): string
    {
        // Try to extract JSON error array from the response
        // Pattern: {"errors":["error1","error2"]}
        if (preg_match('/\{"errors":\[(.*?)\]\}/', $fullError, $matches)) {
            $errorsJson = '{"errors":['.$matches[1].']}';
            $decoded = json_decode($errorsJson, true);

            if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                return implode('; ', $decoded['errors']);
            }
        }

        // Fallback: return full error (TEXT column can handle it)
        return $fullError;
    }
}
