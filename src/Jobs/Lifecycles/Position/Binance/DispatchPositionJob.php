<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Position\Binance;

use Martingalian\Core\Jobs\Lifecycles\Position\DispatchPositionJob as BaseDispatchPositionJob;
use Martingalian\Core\Jobs\Lifecycles\Position\VerifyTradingPairNotOpenJob as VerifyTradingPairNotOpenLifecycle;
use Martingalian\Core\Support\Proxies\JobProxy;

/**
 * DispatchPositionJob (Orchestrator) - Binance
 *
 * Orchestrator that creates step(s) for dispatching a position to Binance.
 *
 * Flow:
 * • Step 1: VerifyTradingPairNotOpenJob - Verify pair not already open (showstopper)
 * • Step 2: SetMarginModeJob - Set margin mode (isolated/cross) [TODO]
 * • Step 3: SetLeverageJob - Set leverage [TODO]
 * • Step 4: PlaceEntryOrderJob - Place entry order [TODO]
 */
class DispatchPositionJob extends BaseDispatchPositionJob
{
    public function compute()
    {
        $resolver = JobProxy::with($this->position->account);

        // Step 1: Verify trading pair not already open
        $verifyLifecycleClass = $resolver->resolve(VerifyTradingPairNotOpenLifecycle::class);
        $verifyLifecycle = new $verifyLifecycleClass($this->position);
        $nextIndex = $verifyLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: 1,
            workflowId: null
        );

        // TODO: Step 2 - Set margin mode
        // TODO: Step 3 - Set leverage
        // TODO: Step 4 - Place entry order

        return [
            'position_id' => $this->position->id,
            'message' => 'Position dispatching initiated',
        ];
    }
}
