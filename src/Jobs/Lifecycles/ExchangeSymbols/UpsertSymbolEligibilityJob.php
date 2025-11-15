<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Jobs\Models\ApiSystem\CheckSymbolEligibilityJob;
use Martingalian\Core\Jobs\Models\ApiSystem\UpsertExchangeSymbolJob;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\Step;

/*
 * UpsertSymbolEligibilityJob
 *
 * • Processes a single symbol from an exchange
 * • Determines eligibility (TAAPI data availability, CMC listing, etc.)
 * • Creates/updates Symbol and ExchangeSymbol records
 * • Updates eligibility status and reason
 */
final class UpsertSymbolEligibilityJob extends BaseQueueableJob
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
        Step::create([
            'class' => UpsertSymbolOnDatabaseJob::class,
            'arguments' => [
                'token' => $this->token,
                'apiSystemId' => $this->apiSystem->id,
            ],
            'block_uuid' => $this->uuid(),
            'index' => 1,
        ]);

        Step::create([
            'class' => UpsertExchangeSymbolJob::class,
            'arguments' => [
                'token' => $this->token,
                'apiSystemId' => $this->apiSystem->id,
            ],
            'block_uuid' => $this->uuid(),
            'index' => 2,
        ]);

        Step::create([
            'class' => CheckSymbolEligibilityJob::class,
            'arguments' => [
                'token' => $this->token,
                'apiSystemId' => $this->apiSystem->id,
            ],
            'block_uuid' => $this->uuid(),
            'index' => 3,
        ]);
    }
}
