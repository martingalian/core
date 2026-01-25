<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Position\Binance;

use Martingalian\Core\Jobs\Lifecycles\Order\PlaceLimitOrdersJob as PlaceLimitOrdersLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Order\PlaceMarketOrderJob as PlaceMarketOrderLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Order\PlaceProfitOrderJob as PlaceProfitOrderLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Order\PlaceStopLossOrderJob as PlaceStopLossOrderLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\DetermineLeverageJob as DetermineLeverageLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\DispatchPositionJob as BaseDispatchPositionJob;
use Martingalian\Core\Jobs\Lifecycles\Position\PreparePositionDataJob as PreparePositionDataLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\SetLeverageJob as SetLeverageLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\SetMarginModeJob as SetMarginModeLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\VerifyOrderNotionalJob as VerifyOrderNotionalLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\VerifyTradingPairNotOpenJob as VerifyTradingPairNotOpenLifecycle;
use Martingalian\Core\Support\Proxies\JobProxy;

/**
 * DispatchPositionJob (Orchestrator) - Binance
 *
 * Orchestrator that creates step(s) for dispatching a position to Binance.
 *
 * Flow:
 * • Step 1: VerifyTradingPairNotOpenJob - Verify pair not already open (showstopper)
 * • Step 2: SetMarginModeJob - Set margin mode (isolated/cross)
 * • Step 3: PreparePositionDataJob - Populate margin, indicators (no leverage)
 * • Step 4: DetermineLeverageJob - Determine optimal leverage based on margin and brackets
 * • Step 5: SetLeverageJob - Set leverage on exchange
 * • Step 6: VerifyOrderNotionalJob - Fetch mark price, validate notional
 * • Step 7: PlaceMarketOrderJob - Place market entry order
 * • Step 8: PlaceLimitOrdersJob - Place limit ladder orders (parallel)
 * • Step 9: PlaceProfitOrderJob - Place take-profit order
 * • Step 10: PlaceStopLossOrderJob - Place stop-loss order
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

        // Step 3: Prepare position data (margin, indicators - leverage determined next)
        $prepareDataLifecycleClass = $resolver->resolve(PreparePositionDataLifecycle::class);
        $prepareDataLifecycle = new $prepareDataLifecycleClass($this->position);
        $nextIndex = $prepareDataLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 4: Determine optimal leverage based on margin and brackets
        $determineLeverageLifecycleClass = $resolver->resolve(DetermineLeverageLifecycle::class);
        $determineLeverageLifecycle = new $determineLeverageLifecycleClass($this->position);
        $nextIndex = $determineLeverageLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 5: Set leverage on exchange
        $leverageLifecycleClass = $resolver->resolve(SetLeverageLifecycle::class);
        $leverageLifecycle = new $leverageLifecycleClass($this->position);
        $nextIndex = $leverageLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 6: Verify order notional (fetch mark price, validate notional)
        $verifyNotionalLifecycleClass = $resolver->resolve(VerifyOrderNotionalLifecycle::class);
        $verifyNotionalLifecycle = new $verifyNotionalLifecycleClass($this->position);
        $nextIndex = $verifyNotionalLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 7: Place entry order
        $placeEntryLifecycleClass = $resolver->resolve(PlaceMarketOrderLifecycle::class);
        $placeEntryLifecycle = new $placeEntryLifecycleClass($this->position);
        $nextIndex = $placeEntryLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 8: Place limit ladder orders (parallel)
        $placeLimitOrdersLifecycleClass = $resolver->resolve(PlaceLimitOrdersLifecycle::class);
        $placeLimitOrdersLifecycle = new $placeLimitOrdersLifecycleClass($this->position);
        $nextIndex = $placeLimitOrdersLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 9: Place take-profit order
        $placeProfitOrderLifecycleClass = $resolver->resolve(PlaceProfitOrderLifecycle::class);
        $placeProfitOrderLifecycle = new $placeProfitOrderLifecycleClass($this->position);
        $nextIndex = $placeProfitOrderLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 10: Place stop-loss order
        $placeStopLossOrderLifecycleClass = $resolver->resolve(PlaceStopLossOrderLifecycle::class);
        $placeStopLossOrderLifecycle = new $placeStopLossOrderLifecycleClass($this->position);
        $nextIndex = $placeStopLossOrderLifecycle->dispatch(
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
