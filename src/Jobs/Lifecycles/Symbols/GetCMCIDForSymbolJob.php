<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Symbols;

use Exception;
use Illuminate\Database\QueryException;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\BaseAssetMapper;
use Martingalian\Core\Models\Symbol;

/*
 * GetCMCIDForSymbolJob
 *
 * • Fetches the CoinMarketCap ID for a symbol token
 * • Uses CMC API to lookup the symbol via /v1/cryptocurrency/map
 * • Creates Symbol record with token and cmc_id
 */
final class GetCMCIDForSymbolJob extends BaseApiableJob
{
    public string $token;

    public ApiSystem $apiSystem;

    public ApiSystem $cmcApiSystem;

    public function __construct(string $token, int $apiSystemId)
    {
        $this->token = $token;
        $this->apiSystem = ApiSystem::findOrFail($apiSystemId); // The exchange (Binance/Bybit)
        $this->cmcApiSystem = ApiSystem::firstWhere('canonical', 'coinmarketcap');
        $this->retries = 100;
    }

    public function relatable()
    {
        return $this->cmcApiSystem; // Relatable to CMC API system for rate limiting
    }

    public function assignExceptionHandler()
    {
        // Use CMC exception handler since we're calling CMC API
        $this->exceptionHandler = BaseExceptionHandler::make('coinmarketcap')
            ->withAccount(Account::admin('coinmarketcap'));
    }

    public function startOrFail()
    {
        return true;
    }

    public function computeApiable()
    {
        // Check if BaseAssetMapper exists for this exchange token
        $mapper = BaseAssetMapper::where('api_system_id', $this->apiSystem->id)
            ->where('exchange_token', mb_strtoupper($this->token))
            ->first();

        // If mapper exists, use the canonical token directly
        if ($mapper) {
            $canonicalToken = $mapper->symbol_token;

            // Check if Symbol already exists
            $existingSymbol = Symbol::where('token', mb_strtoupper($canonicalToken))->first();
            if ($existingSymbol) {
                return [
                    'symbol_id' => $existingSymbol->id,
                    'token' => $this->token,
                    'cmc_id' => $existingSymbol->cmc_id,
                    'message' => 'Symbol already exists (found via BaseAssetMapper)',
                ];
            }

            // Symbol doesn't exist but mapper does - need to search CMC with canonical token
            $account = Account::admin('coinmarketcap');
            $apiMapper = $account->apiMapper();
            $cmcId = $this->searchCMCByToken($canonicalToken, $account, $apiMapper);
        } else {
            // No mapper exists - need to search CMC
            $account = Account::admin('coinmarketcap');
            $apiMapper = $account->apiMapper();

            // Try with original token first
            $cmcId = $this->searchCMCByToken($this->token, $account, $apiMapper);
            $canonicalToken = $this->token; // Track which token worked

            // If no result and token starts with digits, strip them and try again
            if ($cmcId === null && preg_match('/^\d+(.+)$/', $this->token, $matches)) {
                $strippedToken = $matches[1]; // Token without leading digits
                $cmcId = $this->searchCMCByToken($strippedToken, $account, $apiMapper);

                // If we found it with the stripped token, use that as canonical
                if ($cmcId !== null) {
                    $canonicalToken = $strippedToken;

                    // Create BaseAssetMapper to track the exchange→canonical mapping
                    try {
                        BaseAssetMapper::create([
                            'api_system_id' => $this->apiSystem->id,
                            'exchange_token' => mb_strtoupper($this->token),
                            'symbol_token' => mb_strtoupper($canonicalToken),
                        ]);
                    } catch (QueryException $e) {
                        // Mapper already exists from another job - ignore
                    }
                }
            }
        }

        // Create Symbol with CMC ID (handle race condition if another job already created it)
        try {
            $symbol = Symbol::create([
                'token' => mb_strtoupper($canonicalToken),
                'cmc_id' => $cmcId,
                'name' => null,
                'description' => null,
                'site_url' => null,
                'image_url' => null,
            ]);

            return [
                'symbol_id' => $symbol->id,
                'token' => $this->token,
                'cmc_id' => $cmcId,
                'message' => $cmcId ? 'Symbol created with CMC ID' : 'Symbol created (CMC ID not found)',
            ];
        } catch (QueryException $e) {
            // Duplicate key error - another job already created this symbol
            // Fetch the existing symbol and return it without making another CMC API call
            $existingSymbol = Symbol::where('token', mb_strtoupper($canonicalToken))->first();

            // If symbol still doesn't exist (race condition edge case), rethrow to retry
            if (! $existingSymbol) {
                throw $e;
            }

            return [
                'symbol_id' => $existingSymbol->id,
                'token' => $this->token,
                'cmc_id' => $existingSymbol->cmc_id,
                'message' => 'Symbol already exists (created by another job)',
            ];
        }
    }

    /**
     * Search CoinMarketCap API for a symbol by token.
     * Returns CMC ID if found, null otherwise.
     */
    private function searchCMCByToken(string $token, $account, $mapper): ?int
    {
        try {
            // Prepare API properties using the mapper
            $properties = $mapper->prepareSearchSymbolByTokenProperties($token);

            // Call CMC API to search for symbol
            $response = $account->withApi()->getSymbols($properties);
            $result = $mapper->resolveSearchSymbolByTokenResponse($response);

            // Extract CMC ID from the first matching result
            if (isset($result['data']) && is_array($result['data']) && ! empty($result['data'])) {
                // Find exact match (CMC may return multiple results)
                foreach ($result['data'] as $item) {
                    if (mb_strtoupper($item['symbol'] ?? '') === mb_strtoupper($token)) {
                        return $item['id'] ?? null;
                    }
                }

                // Fallback to first result if no exact match
                return $result['data'][0]['id'] ?? null;
            }

            return null;
        } catch (Exception $e) {
            // API error (like 400 Bad Request) - return null to trigger retry with stripped token
            return null;
        }
    }
}
