<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ApiSystem;

use DB;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\Symbol;

/*
 * GetCMCRemainingSymbolDataJob
 *
 * • Fetches remaining symbol data from CoinMarketCap
 * • Gets name, description, site_url, image_url via /v2/cryptocurrency/info
 * • Updates the Symbol record created by previous job
 */
final class GetCMCRemainingSymbolDataJob extends BaseApiableJob
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
        // Lock name format: "cmc_metadata:{TOKEN}"
        $lockName = 'cmc_metadata:'.mb_strtoupper($this->token);
        $lockTimeout = 10; // seconds

        $lockAcquired = DB::select('SELECT GET_LOCK(?, ?) as locked', [$lockName, $lockTimeout])[0]->locked;

        if (! $lockAcquired) {
            // Could not acquire lock within timeout - retry job
            $this->retryJob();

            return;
        }

        try {
            // Get the previous step's response to find the symbol_id
            $previousStep = $this->step->getPrevious()->first();

            if (! $previousStep || ! $previousStep->response) {
                return ['error' => 'Previous job (GetCMCIDForSymbolJob) did not provide symbol_id'];
            }

            $symbolId = $previousStep->response['symbol_id'] ?? null;

            if (! $symbolId) {
                return ['error' => 'symbol_id not found in previous job response'];
            }

            // Find the Symbol created by previous job
            $symbol = Symbol::find($symbolId);

            if (! $symbol) {
                return ['error' => 'Symbol not found with ID: '.$symbolId];
            }

            if (! $symbol->cmc_id) {
                return [
                    'symbol_id' => $symbol->id,
                    'token' => $this->token,
                    'cmc_id' => null,
                    'message' => 'Symbol has no cmc_id - skipping metadata sync',
                ];
            }

            // Check if symbol already has complete metadata (another job might have synced it)
            // Re-fetch if cmc_category is 'other' (fallback value) in case CMC has added proper tags
            $hasCompleteMetadata = $symbol->name && $symbol->description;
            $hasPendingCategory = $symbol->cmc_category === 'other';

            if ($hasCompleteMetadata && ! $hasPendingCategory) {
                return [
                    'symbol_id' => $symbol->id,
                    'token' => $this->token,
                    'cmc_id' => $symbol->cmc_id,
                    'name' => $symbol->name,
                    'message' => 'Symbol metadata already exists - skipping CMC API call',
                ];
            }

            // Use Symbol's existing API method to fetch and update metadata from CMC
            $apiResponse = $symbol->apiSyncCMCData();

            return [
                'symbol_id' => $symbol->id,
                'token' => $this->token,
                'cmc_id' => $symbol->cmc_id,
                'name' => $symbol->fresh()->name,
                'message' => 'Symbol metadata synced from CoinMarketCap',
            ];
        } finally {
            // Always release the lock, even if an exception occurred
            DB::select('SELECT RELEASE_LOCK(?) as released', [$lockName]);
        }
    }
}
