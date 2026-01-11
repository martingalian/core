<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Position\Kucoin;

use Martingalian\Core\Jobs\Lifecycles\Position\DispatchPositionJob as BaseDispatchPositionJob;
use Martingalian\Core\Jobs\Lifecycles\Position\SetLeverageJob as SetLeverageLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\SetMarginModeJob as SetMarginModeLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\VerifyTradingPairNotOpenJob as VerifyTradingPairNotOpenLifecycle;
use Martingalian\Core\Support\Proxies\JobProxy;

/**
 * DispatchPositionJob (Orchestrator) - KuCoin
 *
 * Orchestrator that creates step(s) for dispatching a position to KuCoin.
 *
 * Flow:
 * • Step 1: VerifyTradingPairNotOpenJob - Verify pair not already open (showstopper)
 * • Step 2: SetMarginModeJob - Set margin mode (isolated/cross)
 * • Step 3: SetLeverageJob - Set leverage
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

        // Step 2: Set margin mode (isolated/crossed)
        $marginModeLifecycleClass = $resolver->resolve(SetMarginModeLifecycle::class);
        $marginModeLifecycle = new $marginModeLifecycleClass($this->position);
        $nextIndex = $marginModeLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 3: Set leverage
        $leverageLifecycleClass = $resolver->resolve(SetLeverageLifecycle::class);
        $leverageLifecycle = new $leverageLifecycleClass($this->position);
        $nextIndex = $leverageLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // TODO: Step 4 - Place entry order

        return [
            'position_id' => $this->position->id,
            'message' => 'Position dispatching initiated',
        ];
    }
}
