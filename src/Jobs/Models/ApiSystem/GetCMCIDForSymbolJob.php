<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ApiSystem;

use DB;
use Exception;
use Illuminate\Database\QueryException;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\BaseAssetMapper;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Symbol;
use Martingalian\Core\Support\NotificationMessageBuilder;
use Martingalian\Core\Support\NotificationService;

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
        // Acquire advisory lock to prevent duplicate CMC API calls for the same token
        // Lock name format: "cmc_symbol:{TOKEN}"
        $lockName = 'cmc_symbol:'.mb_strtoupper($this->token);
        $lockTimeout = 10; // seconds

        $lockAcquired = DB::select('SELECT GET_LOCK(?, ?) as locked', [$lockName, $lockTimeout])[0]->locked;

        if (! $lockAcquired) {
            // Could not acquire lock within timeout - retry job
            $this->retryJob();

            return;
        }

        try {
            // Check if BaseAssetMapper exists for this exchange token
            $mapper = BaseAssetMapper::where('api_system_id', $this->apiSystem->id)
                ->where('exchange_token', mb_strtoupper($this->token))
                ->first();

            // Determine canonical token from mapper or use original token
            $canonicalToken = $mapper ? $mapper->symbol_token : $this->token;

            // Check if Symbol already exists with CMC ID
            $existingSymbol = Symbol::where('token', mb_strtoupper($canonicalToken))->first();
            if ($existingSymbol && $existingSymbol->cmc_id !== null) {
                return [
                    'symbol_id' => $existingSymbol->id,
                    'token' => $this->token,
                    'cmc_id' => $existingSymbol->cmc_id,
                    'message' => 'Symbol already exists with CMC ID',
                ];
            }

            // Search CMC for the canonical token
            $account = Account::admin('coinmarketcap');
            $apiMapper = $account->apiMapper();
            $cmcId = $this->searchCMCByToken($canonicalToken, $account, $apiMapper);

            // If not found and token has leading digits, try stripping them
            if ($cmcId === null && preg_match('/^\d+(.+)$/', $canonicalToken, $matches)) {
                $strippedToken = $matches[1];
                $cmcId = $this->searchCMCByToken($strippedToken, $account, $apiMapper);

                if ($cmcId !== null) {
                    $canonicalToken = $strippedToken;

                    // Create BaseAssetMapper to track exchange→canonical mapping
                    if (! $mapper) {
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

            // If existing Symbol found, update it with CMC ID
            if ($existingSymbol) {
                if ($cmcId !== null) {
                    $existingSymbol->update(['cmc_id' => $cmcId]);

                    return [
                        'symbol_id' => $existingSymbol->id,
                        'token' => $this->token,
                        'cmc_id' => $cmcId,
                        'message' => 'Symbol updated with CMC ID',
                    ];
                }

                // Symbol exists but CMC ID not found
                $this->sendCmcIdNotFoundNotification($canonicalToken);

                return [
                    'symbol_id' => $existingSymbol->id,
                    'token' => $this->token,
                    'cmc_id' => null,
                    'message' => 'Symbol exists (CMC ID not found)',
                ];
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

                // Send notification if CMC ID was not found
                if ($cmcId === null) {
                    $this->sendCmcIdNotFoundNotification($canonicalToken);
                }

                return [
                    'symbol_id' => $symbol->id,
                    'token' => $this->token,
                    'cmc_id' => $cmcId,
                    'message' => $cmcId ? 'Symbol created with CMC ID' : 'Symbol created (CMC ID not found)',
                ];
            } catch (QueryException $e) {
                // Duplicate key error - another job already created this symbol
                // Fetch the existing symbol
                $existingSymbol = Symbol::where('token', mb_strtoupper($canonicalToken))->first();

                // If symbol still doesn't exist (race condition edge case), rethrow to retry
                if (! $existingSymbol) {
                    throw $e;
                }

                // If existing symbol has no CMC ID but we found one, update it
                if ($existingSymbol->cmc_id === null && $cmcId !== null) {
                    $existingSymbol->update(['cmc_id' => $cmcId]);

                    return [
                        'symbol_id' => $existingSymbol->id,
                        'token' => $this->token,
                        'cmc_id' => $cmcId,
                        'message' => 'Symbol already exists - updated with CMC ID',
                    ];
                }

                // If existing symbol has no CMC ID and we didn't find one either, notify
                if ($existingSymbol->cmc_id === null && $cmcId === null) {
                    $this->sendCmcIdNotFoundNotification($canonicalToken);
                }

                return [
                    'symbol_id' => $existingSymbol->id,
                    'token' => $this->token,
                    'cmc_id' => $existingSymbol->cmc_id,
                    'message' => 'Symbol already exists (created by another job)',
                ];
            }
        } finally {
            // Always release the lock, even if an exception occurred
            DB::select('SELECT RELEASE_LOCK(?) as released', [$lockName]);
        }
    }

    /**
     * Search CoinMarketCap API for a symbol by token.
     * Returns CMC ID if found, null otherwise.
     *
     * Note: API errors (rate limits, network issues, etc.) will bubble up
     * to the exception handler which will retry the job appropriately.
     */
    private function searchCMCByToken(string $token, $account, $mapper): ?int
    {
        try {
            // Prepare API properties using the mapper
            $properties = $mapper->prepareSearchSymbolByTokenProperties($token);

            // Link API request log to the Step if running via Step dispatcher
            if (isset($this->step)) {
                $properties->set('relatable', $this->step);
            }

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
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // 400 Bad Request = invalid symbol name (too long, malformed, etc.)
            // Return null to indicate symbol not found, allowing job to complete gracefully
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 400) {
                return null;
            }

            // Re-throw other client exceptions (will be handled by exception handler)
            throw $e;
        }
    }

    /**
     * Send admin notification when CMC ID is not found for a symbol.
     */
    private function sendCmcIdNotFoundNotification(string $canonicalToken): void
    {
        $exchangeName = $this->apiSystem->name;
        $exchangeCanonical = $this->apiSystem->canonical;
        $originalToken = $this->token;

        $message = "⚠️ Symbol '{$canonicalToken}' from {$exchangeName} could not be found on CoinMarketCap.\n\n";
        $message .= "📋 DETAILS:\n";
        $message .= "Exchange Token: {$originalToken}\n";
        $message .= "Canonical Token: {$canonicalToken}\n";
        $message .= "Exchange: {$exchangeName} ({$exchangeCanonical})\n\n";
        $message .= "The symbol was created in the database without CMC metadata (cmc_id, name, description).\n\n";
        $message .= "🔍 POSSIBLE REASONS:\n";
        $message .= "• Symbol not yet listed on CoinMarketCap\n";
        $message .= "• Symbol uses different ticker on CMC (e.g., SOLAYER → LAYER)\n";
        $message .= "• Symbol is a test token or delisted\n";
        $message .= "• Token name has prefix/suffix variations\n\n";
        $message .= "✅ HOW TO FIX (Exchange Naming Mismatch):\n\n";
        $message .= "If this is a known token with a different CMC symbol, use the symbols:merge command:\n\n";
        $message .= "1. First, find the correct CMC symbol by searching:\n";
        $message .= "   [CMD]https://coinmarketcap.com/search?q={$canonicalToken}[/CMD]\n\n";
        $message .= "2. Then merge the symbols using this command:\n";
        $message .= "   [CMD]php artisan symbols:merge --from={$canonicalToken} --to=CORRECT_CMC_SYMBOL --exchange={$exchangeCanonical}[/CMD]\n\n";
        $message .= "   Example (SOLAYER → LAYER on Bybit):\n";
        $message .= "   [CMD]php artisan symbols:merge --from=SOLAYER --to=LAYER --exchange=bybit[/CMD]\n\n";
        $message .= "This command will:\n";
        $message .= "• Create BaseAssetMapper ({$exchangeCanonical}.{$canonicalToken} → CORRECT_CMC_SYMBOL)\n";
        $message .= "• Migrate all ExchangeSymbols to the correct Symbol\n";
        $message .= "• Delete the incorrect Symbol\n";
        $message .= "• Fetch CMC metadata automatically\n\n";
        $message .= "🔧 ALTERNATIVE (Manual Fix):\n\n";
        $message .= "If you prefer to fix it manually:\n\n";
        $message .= "1. Create BaseAssetMapper:\n";
        $message .= "[CMD]INSERT INTO base_asset_mappers (api_system_id, exchange_token, symbol_token) VALUES ({$this->apiSystem->id}, '{$originalToken}', 'CORRECT_CMC_SYMBOL');[/CMD]\n\n";
        $message .= "2. Update the symbol:\n";
        $message .= "[CMD]UPDATE symbols SET token='CORRECT_CMC_SYMBOL', cmc_id=CMC_ID WHERE token='{$canonicalToken}';[/CMD]\n\n";
        $message .= "3. Dispatch metadata job:\n";
        $message .= "[CMD]php artisan tinker --execute=\"\\Martingalian\\Core\\Models\\Step::create(['class' => '\\Martingalian\\Core\\Jobs\\Lifecycles\\Symbols\\GetCMCRemainingSymbolDataJob', 'arguments' => ['token' => 'CORRECT_CMC_SYMBOL', 'apiSystemId' => {$this->apiSystem->id}]]);\"[/CMD]";

        // Use Symbol as relatable for throttling context
        $symbol = Symbol::where('token', mb_strtoupper($canonicalToken))->first();

        // Removed NotificationService::send - invalid canonical: symbol_cmc_id_not_found
    }
}
