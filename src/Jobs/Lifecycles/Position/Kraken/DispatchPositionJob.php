<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Position\Kraken;

use Martingalian\Core\Jobs\Lifecycles\Position\DispatchPositionJob as BaseDispatchPositionJob;
use Martingalian\Core\Jobs\Lifecycles\Position\Kraken\SetLeveragePreferencesJob as SetLeveragePreferencesLifecycle;
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
 * • Step 3: PlaceEntryOrderJob - Place entry order [TODO]
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

        // TODO: Step 3 - Place entry order

        return [
            'position_id' => $this->position->id,
            'message' => 'Position dispatching initiated',
        ];
    }
}
