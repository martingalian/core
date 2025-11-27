<?php

declare(strict_types=1);

namespace Martingalian\Core\Transitions;

use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Pending;
use Spatie\ModelStates\Transition;

final class RunningToPending extends Transition
{
    private Step $step;

    public function __construct(Step $step)
    {
        $this->step = $step;
    }

    public function canTransition(): bool
    {
        Step::log($this->step->id, 'transition', '→→→ RunningToPending::canTransition() called');
        Step::log($this->step->id, 'transition', 'Always returns TRUE (no restrictions)');
        return true;
    }

    public function handle(): Step
    {
        Step::log($this->step->id, 'transition', '╔═══════════════════════════════════════════════════════════╗');
        Step::log($this->step->id, 'transition', '║   RunningToPending::handle() - RETRY TRANSITION          ║');
        Step::log($this->step->id, 'transition', '╚═══════════════════════════════════════════════════════════╝');

        Step::log($this->step->id, 'transition', 'BEFORE INCREMENT:');
        Step::log($this->step->id, 'transition', '  - Current retries: '.$this->step->retries);
        Step::log($this->step->id, 'transition', '  - Current state: '.$this->step->state);
        Step::log($this->step->id, 'transition', '  - is_throttled flag: '.($this->step->is_throttled ? 'true' : 'false'));

        // Conditionally increment retry count based on is_throttled flag
        if ($this->step->is_throttled) {
            Step::log($this->step->id, 'transition', '⚠️ THROTTLE DETECTED - SKIPPING RETRY INCREMENT');
            Step::log($this->step->id, 'transition', '   → is_throttled = true');
            Step::log($this->step->id, 'transition', '   → This is a reschedule, not a retry');
            Step::log($this->step->id, 'transition', '   → retries will remain: '.$this->step->retries);
            info_if("[RunningToPending.handle] - Step ID {$this->step->id} is throttled - retries NOT incremented (staying at {$this->step->retries})");
        } else {
            Step::log($this->step->id, 'transition', '⚠️⚠️⚠️ THIS IS A REAL RETRY - INCREMENTING RETRIES ⚠️⚠️⚠️');
            Step::log($this->step->id, 'transition', 'Calling increment(\'retries\')...');
            $this->step->increment('retries');
            Step::log($this->step->id, 'transition', '✓ increment() completed');
            Step::log($this->step->id, 'transition', 'AFTER INCREMENT:');
            Step::log($this->step->id, 'transition', '  - New retries value: '.$this->step->retries);
            info_if("[RunningToPending.handle] - Step ID {$this->step->id} retries incremented to ".$this->step->retries);
        }

        /*
        $this->step->logApplicationEvent(
            'Step retries incremented to '.$this->step->retries,
            self::class,
            __FUNCTION__
        );
        */

        Step::log($this->step->id, 'transition', 'RESETTING TIMERS:');
        Step::log($this->step->id, 'transition', '  - Setting started_at = null');
        $this->step->started_at = null;
        Step::log($this->step->id, 'transition', '  - Setting completed_at = null');
        $this->step->completed_at = null;
        Step::log($this->step->id, 'transition', '  - Setting duration = 0');
        $this->step->duration = 0;
        Step::log($this->step->id, 'transition', '✓ Timers reset complete');

        // Reset the state to Pending
        Step::log($this->step->id, 'transition', 'CHANGING STATE:');
        Step::log($this->step->id, 'transition', '  - Old state: '.$this->step->state);
        Step::log($this->step->id, 'transition', '  - Creating new Pending state object...');
        $this->step->state = new Pending($this->step);
        Step::log($this->step->id, 'transition', '  - New state: '.$this->step->state);

        Step::log($this->step->id, 'transition', 'Calling save() to persist changes...');
        $this->step->save();
        Step::log($this->step->id, 'transition', '✓ save() completed - all changes persisted');

        info_if("[RunningToPending.handle] Step ID {$this->step->id} transitioned back to Pending (retry) (timers reset).");

        Step::log($this->step->id, 'transition', 'FINAL STATE AFTER TRANSITION:');
        Step::log($this->step->id, 'transition', '  - State: Pending');
        Step::log($this->step->id, 'transition', '  - Retries: '.$this->step->retries);
        Step::log($this->step->id, 'transition', '  - Timers: RESET');
        Step::log($this->step->id, 'transition', '✓ RunningToPending::handle() completed successfully');
        Step::log($this->step->id, 'transition', '╚═══════════════════════════════════════════════════════════╝');

        /*
        $this->step->logApplicationEvent(
            'Step transitioned back to Pending (retry) (timers reset).',
            self::class,
            __FUNCTION__
        );
        */

        return $this->step;
    }
}
