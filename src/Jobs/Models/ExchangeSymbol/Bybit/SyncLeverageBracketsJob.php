<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ExchangeSymbol\Bybit;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ExchangeSymbol;
use RuntimeException;

/**
 * SyncLeverageBracketsJob - Bybit
 *
 * Fetches leverage brackets for a single Bybit symbol.
 * Bybit requires querying per-symbol via the API.
 */
final class SyncLeverageBracketsJob extends BaseApiableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public function __construct(int $exchangeSymbolId)
    {
        $this->exchangeSymbol = ExchangeSymbol::with(['symbol', 'quote', 'apiSystem'])
            ->findOrFail($exchangeSymbolId);
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function assignExceptionHandler()
    {
        $canonical = $this->exchangeSymbol->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount(Account::admin($canonical));
    }

    public function startOrFail()
    {
        // Only run for Bybit
        return $this->exchangeSymbol->apiSystem->canonical === 'bybit';
    }

    public function computeApiable()
    {
        // Build symbol string using the mapper (handles PERP suffix for USDC contracts)
        $mapper = $this->exchangeSymbol->apiSystem->apiMapper();
        $symbolString = $mapper->baseWithQuote(
            $this->exchangeSymbol->symbol->token,
            $this->exchangeSymbol->quote->canonical
        );

        // Prepare API request with symbol parameter
        $properties = $mapper->prepareQueryLeverageBracketsDataProperties(
            $this->exchangeSymbol->apiSystem,
            $symbolString
        );

        $account = Account::admin($this->exchangeSymbol->apiSystem->canonical);
        $properties->set('account', $account);

        // Make API request
        $apiResponse = $account->withApi()->getLeverageBrackets($properties);

        // Resolve response
        $resolved = $mapper->resolveLeverageBracketsDataResponse($apiResponse);

        if (! is_array($resolved)) {
            throw new RuntimeException('Invalid leverage brackets response.');
        }

        // Extract brackets (response should have one entry for this symbol)
        $brackets = $resolved[0]['brackets'] ?? [];

        // Update JSON blob on exchange_symbols
        $this->exchangeSymbol->update([
            'leverage_brackets' => $brackets,
        ]);

        return [
            'symbol' => $symbolString,
            'brackets_count' => count($brackets),
        ];
    }
}
