<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Position\Kraken;

use Martingalian\Core\Jobs\Lifecycles\Position\DispatchPositionJob as BaseDispatchPositionJob;
use Martingalian\Core\Jobs\Lifecycles\Position\Kraken\SetLeveragePreferencesJob as SetLeveragePreferencesLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\PlaceMarketOrderJob as PlaceMarketOrderLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\PreparePositionDataJob as PreparePositionDataLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\VerifyOrderNotionalJob as VerifyOrderNotionalLifecycle;
use Martingalian\Core\Jobs\Lifecycles\Position\VerifyTradingPairNotOpenJob as VerifyTradingPairNotOpenLifecycle;
use Martingalian\Core\Support\Proxies\JobProxy;

/**
 * DispatchPositionJob (Orchestrator) - Kraken
 *
 * Orchestrator that creates step(s) for dispatching a position to Kraken.
 *
 * Kraken combines margin mode + leverage in a single API call (leveragepreferences).
 * - Setting maxLeverage = ISOLATED margin
 * - Omitting maxLeverage = CROSS margin
 *
 * Flow:
 * • Step 1: VerifyTradingPairNotOpenJob - Verify pair not already open (showstopper)
 * • Step 2: SetLeveragePreferencesJob - Set margin mode + leverage (combined)
 * • Step 3: PreparePositionDataJob - Populate margin, leverage, indicators
 * • Step 4: VerifyOrderNotionalJob - Fetch mark price, validate notional
 * • Step 5: PlaceMarketOrderJob - Place market entry order
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

        // Step 2: Set leverage preferences (margin mode + leverage combined)
        // Kraken uses a single endpoint that handles both:
        // - isolated margin: sets maxLeverage
        // - crossed margin: omits maxLeverage (dynamic leverage based on wallet)
        $leveragePrefsLifecycleClass = $resolver->resolve(SetLeveragePreferencesLifecycle::class);
        $leveragePrefsLifecycle = new $leveragePrefsLifecycleClass($this->position);
        $nextIndex = $leveragePrefsLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 3: Prepare position data (margin, leverage, indicators)
        $prepareDataLifecycleClass = $resolver->resolve(PreparePositionDataLifecycle::class);
        $prepareDataLifecycle = new $prepareDataLifecycleClass($this->position);
        $nextIndex = $prepareDataLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 4: Verify order notional (fetch mark price, validate notional)
        $verifyNotionalLifecycleClass = $resolver->resolve(VerifyOrderNotionalLifecycle::class);
        $verifyNotionalLifecycle = new $verifyNotionalLifecycleClass($this->position);
        $nextIndex = $verifyNotionalLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 5: Place entry order
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
