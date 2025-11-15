<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Jobs\Models\ApiSystem\GetCMCIDForSymbolJob;
use Martingalian\Core\Jobs\Models\ApiSystem\GetCMCRemainingSymbolDataJob;
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
final class UpsertSymbolOnDatabaseJob extends BaseQueueableJob
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
        // Get symbolData from grandparent step (GetAllSymbolsFromExchangeJob)
        $symbolData = $this->getSymbolDataFromGrandparent();

        if (! $symbolData) {
            return ['error' => 'Could not find symbol data from GetAllSymbolsFromExchangeJob'];
        }

        // Extract baseAsset from symbolData (handles special cases like SOLPERP)
        $baseAsset = $symbolData['baseAsset'];

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
            'block_uuid' => $this->uuid(),
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

    private function getSymbolDataFromGrandparent(): ?array
    {
        // Navigate via block_uuid to find the parent (UpsertSymbolEligibilityJob)
        // Current step's block_uuid is shared with siblings
        // Parent is the step that created this block
        $parent = Step::where('child_block_uuid', $this->step->block_uuid)->first();

        // If no parent found via child_block_uuid, try finding GetAllSymbolsFromExchangeJob directly
        if (! $parent) {
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
