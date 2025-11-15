<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Jobs\Lifecycles\Symbols\GetCMCIDForSymbolJob;
use Martingalian\Core\Jobs\Lifecycles\Symbols\GetCMCRemainingSymbolDataJob;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\Quote;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Models\Symbol;

/*
 * UpsertSymbolOnDatabaseJob
 *
 * • Verifies if the Symbol exists in the database for the given token
 * • If Symbol doesn't exist, becomes a parent and dispatches CMC lookup jobs
 * • If Symbol exists, completes successfully
 */
final class UpsertSymbolOnDatabaseJob extends BaseApiableJob
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
        // Parse token to extract base and quote assets
        $parsed = $this->parseToken($this->token);

        if (! $parsed) {
            return ['message' => 'Could not parse token - no matching quote found'];
        }

        [$baseAsset, $quoteAsset] = $parsed;

        // Check if Symbol exists (handles BaseAssetMapper for tokens like 1000BONK → BONK)
        $symbol = Symbol::getByExchangeBaseAsset($baseAsset, $this->apiSystem);

        if ($symbol) {
            // Symbol exists, nothing to do
            return [
                'symbol_id' => $symbol->id,
                'message' => 'Symbol already exists in database',
            ];
        }

        // Symbol doesn't exist - dispatch child jobs to fetch from CMC
        // IMPORTANT: Mark this step as a parent before creating children
        $childBlockUuid = $this->step->makeItAParent();

        Step::create([
            'class' => GetCMCIDForSymbolJob::class,
            'arguments' => [
                'token' => $baseAsset,
                'apiSystemId' => $this->apiSystem->id,
            ],
            'block_uuid' => $childBlockUuid,
            'index' => 1,
        ]);

        Step::create([
            'class' => GetCMCRemainingSymbolDataJob::class,
            'arguments' => [
                'token' => $baseAsset,
                'apiSystemId' => $this->apiSystem->id,
            ],
            'block_uuid' => $childBlockUuid,
            'index' => 2,
        ]);

        return [
            'base_asset' => $baseAsset,
            'message' => 'Symbol not found - CMC lookup jobs dispatched',
        ];
    }

    /**
     * Parse trading pair token to extract base and quote assets.
     *
     * @return array{0: string, 1: string}|null Returns [baseAsset, quoteAsset] or null if no match
     */
    private function parseToken(string $token): ?array
    {
        // Get all quotes ordered by length (longest first to avoid partial matches)
        $quotes = Quote::orderByRaw('LENGTH(canonical) DESC')->pluck('canonical');

        foreach ($quotes as $quoteToken) {
            if (str_ends_with($token, $quoteToken)) {
                $baseAsset = mb_substr($token, 0, -mb_strlen($quoteToken));

                return [$baseAsset, $quoteToken];
            }
        }

        return null;
    }
}
