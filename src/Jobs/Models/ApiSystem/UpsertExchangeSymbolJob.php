<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ApiSystem;

use Carbon\Carbon;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Quote;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Models\Symbol;

/*
 * UpsertExchangeSymbolJob
 *
 * • Creates or updates ExchangeSymbol record
 * • Links Symbol to ApiSystem with Quote
 * • Sets initial trading parameters (precision, min_notional, etc.)
 */
final class UpsertExchangeSymbolJob extends BaseQueueableJob
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

    public function compute()
    {
        // Get previous sibling step (UpsertSymbolJob) to find symbol_id
        $previousStep = $this->step->getPrevious()->first();

        if (! $previousStep || ! $previousStep->response) {
            return ['error' => 'Previous job (UpsertSymbolJob) did not provide response'];
        }

        // Get symbol_id from previous step or its children
        $symbolId = $this->getSymbolId($previousStep);

        if (! $symbolId) {
            return ['error' => 'Could not find symbol_id from previous step'];
        }

        // Get symbolData from grandparent step (GetAllSymbolsFromExchangeJob)
        $symbolData = $this->getSymbolDataFromGrandparent();

        if (! $symbolData) {
            return ['error' => 'Could not find symbol data from GetAllSymbolsFromExchangeJob'];
        }

        // Get quote asset from symbolData (quoteAsset or marginAsset)
        $quoteCanonical = $symbolData['quoteAsset'] ?? $symbolData['marginAsset'] ?? null;

        if (! $quoteCanonical) {
            return ['error' => 'Could not determine quote asset from symbolData for: '.$this->token];
        }

        // Find Quote record by canonical
        $quote = Quote::where('canonical', mb_strtoupper($quoteCanonical))->first();

        if (! $quote) {
            return ['error' => 'Quote not found in database: '.$quoteCanonical];
        }

        // Create or update ExchangeSymbol
        $exchangeSymbol = ExchangeSymbol::updateOrCreate(
            [
                'symbol_id' => $symbolId,
                'api_system_id' => $this->apiSystem->id,
                'quote_id' => $quote->id,
            ],
            [
                'is_active' => true,
                'price_precision' => $symbolData['pricePrecision'],
                'quantity_precision' => $symbolData['quantityPrecision'],
                'min_notional' => $symbolData['minNotional'],
                'tick_size' => $symbolData['tickSize'],
                'min_price' => $symbolData['minPrice'],
                'max_price' => $symbolData['maxPrice'],
                'delivery_ts_ms' => $symbolData['deliveryDate'] ?? 0,
                'delivery_at' => $symbolData['deliveryDate'] > 0
                    ? Carbon::createFromTimestampMs($symbolData['deliveryDate'])
                    : null,
                'symbol_information' => json_encode($symbolData),
            ]
        );

        return [
            'exchange_symbol_id' => $exchangeSymbol->id,
            'symbol_id' => $symbolId,
            'quote_id' => $quote->id,
            'message' => 'ExchangeSymbol created/updated',
        ];
    }

    private function getSymbolId($previousStep): ?int
    {
        // Check if previous step has symbol_id in response
        if (isset($previousStep->response['symbol_id'])) {
            return $previousStep->response['symbol_id'];
        }

        // Otherwise, get it from first child (GetCMCIDForSymbolJob)
        $firstChild = $previousStep->childSteps()->where('index', 1)->first();

        if ($firstChild && isset($firstChild->response['symbol_id'])) {
            return $firstChild->response['symbol_id'];
        }

        return null;
    }

    private function getSymbolDataFromGrandparent(): ?array
    {
        // Navigate via block_uuid to find the parent (UpsertSymbolEligibilityJob)
        // Current step's block_uuid is shared with siblings
        // Parent is the step that created this block
        $parent = Step::where('child_block_uuid', $this->step->block_uuid)->first();

        // If no parent found via child_block_uuid, try finding sibling at index=1
        // and look at GetAllSymbolsFromExchangeJob response directly
        if (! $parent) {
            // Find GetAllSymbolsFromExchangeJob for this API system
            $getAllSymbolsJob = Step::where('class', 'Martingalian\\Core\\Jobs\\Lifecycles\\ApiSystem\\GetAllSymbolsFromExchangeJob')
                ->where('relatable_id', $this->apiSystem->id)
                ->orderBy('id', 'desc')
                ->first();

            if (! $getAllSymbolsJob || ! $getAllSymbolsJob->response) {
                return null;
            }

            $symbolsData = $getAllSymbolsJob->response['symbols_data'] ?? [];

            return $symbolsData[$this->token] ?? null;
        }

        // Navigate to grandparent (GetAllSymbolsFromExchangeJob)
        $grandparent = Step::where('child_block_uuid', $parent->block_uuid)->first();

        if (! $grandparent || ! $grandparent->response) {
            return null;
        }

        // Extract symbolData for this token
        $symbolsData = $grandparent->response['symbols_data'] ?? [];

        return $symbolsData[$this->token] ?? null;
    }
}
