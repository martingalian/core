<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ExchangeSymbol\Binance;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Quote;
use Martingalian\Core\Models\Symbol;
use RuntimeException;
use Throwable;

/**
 * SyncLeverageBracketsJob - Binance
 *
 * Fetches ALL leverage brackets for Binance in a single API call.
 * Updates each exchange_symbols.leverage_brackets JSON column.
 */
final class SyncLeverageBracketsJob extends BaseApiableJob
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
        $this->exceptionHandler = BaseExceptionHandler::make('binance')
            ->withAccount(Account::admin('binance'));
    }

    public function startOrFail()
    {
        return $this->apiSystem->canonical === 'binance' && $this->apiSystem->is_exchange;
    }

    public function computeApiable()
    {
        // Binance returns all leverage brackets in one call
        $response = $this->apiSystem->apiQueryLeverageBracketsData();
        $allBrackets = $response->result ?? null;

        if (! is_array($allBrackets)) {
            throw new RuntimeException('Invalid leverage brackets response.');
        }

        // Filter out test and irrelevant symbols
        $allBrackets = $this->removeUnnecessarySymbols($allBrackets);

        foreach ($allBrackets as $entry) {
            $symbolCode = $entry['symbol'] ?? null;
            if (! $symbolCode) {
                continue;
            }

            try {
                // Identify base/quote using the API's canonical mapper
                $pair = $this->apiSystem->apiMapper()->identifyBaseAndQuote($symbolCode);
            } catch (Throwable $e) {
                continue;
            }

            if (! isset($pair['base'], $pair['quote'])) {
                continue;
            }

            // Get or skip unknown symbols and quotes
            $symbol = Symbol::getByExchangeBaseAsset($pair['base'], $this->apiSystem);
            if (! $symbol) {
                continue;
            }

            $quote = Quote::firstWhere('canonical', $pair['quote']);
            if (! $quote) {
                continue;
            }

            // Update JSON blob on exchange_symbols
            ExchangeSymbol::where([
                'symbol_id' => $symbol->id,
                'api_system_id' => $this->apiSystem->id,
                'quote_id' => $quote->id,
            ])->update([
                'leverage_brackets' => $entry['brackets'] ?? [],
            ]);
        }

        return [
            'processed' => count($allBrackets),
        ];
    }

    public function removeUnnecessarySymbols(array $entries): array
    {
        // Filter out symbols that contain underscores or end with "SETTLED"
        return array_filter($entries, function ($entry) {
            $symbol = $entry['symbol'] ?? '';

            return ! str_contains($symbol, '_') && ! str_ends_with($symbol, 'SETTLED');
        });
    }
}
