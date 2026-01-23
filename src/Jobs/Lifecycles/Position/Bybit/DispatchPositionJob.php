<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Position\Bybit;

use Martingalian\Core\Jobs\Lifecycles\Position\DispatchPositionJob as BaseDispatchPositionJob;
use Martingalian\Core\Jobs\Lifecycles\Orders\PlaceMarketOrderJob as PlaceMarketOrderLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\PreparePositionDataJob as PreparePositionDataLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\SetLeverageJob as SetLeverageLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\SetMarginModeJob as SetMarginModeLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\VerifyOrderNotionalJob as VerifyOrderNotionalLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\VerifyTradingPairNotOpenJob as VerifyTradingPairNotOpenLifecycle;
use Martingalian\Core\Support\Proxies\JobProxy;

/**
 * DispatchPositionJob (Orchestrator) - Bybit
 *
 * Orchestrator that creates step(s) for dispatching a position to Bybit.
 *
 * Flow:
 * • Step 1: VerifyTradingPairNotOpenJob - Verify pair not already open (showstopper)
 * • Step 2: SetMarginModeJob - Set margin mode (isolated/cross)
 * • Step 3: SetLeverageJob - Set leverage
 * • Step 4: PreparePositionDataJob - Populate margin, leverage, indicators
 * • Step 5: VerifyOrderNotionalJob - Fetch mark price, validate notional
 * • Step 6: PlaceMarketOrderJob - Place market entry order
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

        // Step 4: Prepare position data (margin, leverage, indicators)
        $prepareDataLifecycleClass = $resolver->resolve(PreparePositionDataLifecycle::class);
        $prepareDataLifecycle = new $prepareDataLifecycleClass($this->position);
        $nextIndex = $prepareDataLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 5: Verify order notional (fetch mark price, validate notional)
        $verifyNotionalLifecycleClass = $resolver->resolve(VerifyOrderNotionalLifecycle::class);
        $verifyNotionalLifecycle = new $verifyNotionalLifecycleClass($this->position);
        $nextIndex = $verifyNotionalLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 6: Place entry order
        $placeEntryLifecycleClass = $resolver->resolve(PlaceMarketOrderLifecycle::class);
        $placeEntryLifecycle = new $placeEntryLifecycleClass($this->position);
        $nextIndex = $placeEntryLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        return [
            'position_id' => $this->position->id,
            'message' => 'Position dispatching initiated',
        ];
    }
}
