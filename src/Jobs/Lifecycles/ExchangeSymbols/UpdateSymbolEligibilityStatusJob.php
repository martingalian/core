<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\ApiSystem;

/*
 * UpdateSymbolEligibilityStatusJob
 *
 * • Updates ExchangeSymbol is_eligible and ineligible_reason fields
 * • Sets final eligibility status based on all checks
 * • Completes the symbol eligibility workflow
 */
final class UpdateSymbolEligibilityStatusJob extends BaseQueueableJob
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

    public function compute(): void
    {
        // TODO: Implement eligibility status update
    }
}
