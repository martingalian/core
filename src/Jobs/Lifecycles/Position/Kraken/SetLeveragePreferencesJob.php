<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Position\Kraken;

use Martingalian\Core\Abstracts\BasePositionLifecycle;
use Martingalian\Core\Jobs\Atomic\Position\SetLeveragePreferencesJob as AtomicSetLeveragePreferencesJob;
use Martingalian\Core\Models\Step;

/**
 * SetLeveragePreferencesJob (Lifecycle) - Kraken Only
 *
 * Orchestrator that creates step(s) for setting leverage preferences on Kraken.
 * This is Kraken-specific because Kraken combines margin mode + leverage in one API call.
 *
 * Kraken API behavior:
 * - Setting maxLeverage = ISOLATED margin mode with that leverage
 * - Omitting maxLeverage = CROSS margin mode (dynamic leverage based on wallet balance)
 *
 * Flow:
 * - Step N: SetLeveragePreferencesJob (Atomic) - Sets margin mode + leverage on Kraken
 */
class SetLeveragePreferencesJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicSetLeveragePreferencesJob::class),
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
