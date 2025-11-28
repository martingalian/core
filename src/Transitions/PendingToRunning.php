<?php

declare(strict_types=1);

namespace Martingalian\Core\Transitions;

use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Running;
use Spatie\ModelStates\Transition;

final class PendingToRunning extends Transition
{
    public function __construct(
        private Step $step
    ) {}

    public function handle(): Step
    {
        Step::log($this->step->id, 'transition', '╔═══════════════════════════════════════════════════════════╗');
        Step::log($this->step->id, 'transition', '║   PendingToRunning::handle() - DIRECT START              ║');
        Step::log($this->step->id, 'transition', '╚═══════════════════════════════════════════════════════════╝');
        Step::log($this->step->id, 'transition', '⚠️ NOTE: This transition bypasses Dispatched state');
        Step::log($this->step->id, 'transition', '→→→ Setting same fields as DispatchedToRunning for consistency ←←←');

        Step::log($this->step->id, 'transition', 'BEFORE TRANSITION:');
        Step::log($this->step->id, 'transition', '  - Current state: '.$this->step->state);
        Step::log($this->step->id, 'transition', '  - Hostname: '.($this->step->hostname ?? 'null'));
        Step::log($this->step->id, 'transition', '  - started_at: '.($this->step->started_at ?? 'null'));
        Step::log($this->step->id, 'transition', '  - is_throttled: '.($this->step->is_throttled ? 'true' : 'false'));

        Step::log($this->step->id, 'transition', 'SETTING ATTRIBUTES:');
        Step::log($this->step->id, 'transition', '  - Setting hostname to: '.gethostname());
        $this->step->hostname = gethostname();

        $startedAt = now();
        Step::log($this->step->id, 'transition', '  - Setting started_at to: '.$startedAt->format('Y-m-d H:i:s.u'));
        $this->step->started_at = $startedAt;

        Step::log($this->step->id, 'transition', '  - Setting is_throttled to: FALSE (no longer throttled)');
        $this->step->is_throttled = false;

        Step::log($this->step->id, 'transition', 'CHANGING STATE:');
        Step::log($this->step->id, 'transition', '  - Creating new Running state object...');
        $this->step->state = new Running($this->step);
        Step::log($this->step->id, 'transition', '  - New state: '.$this->step->state);

        Step::log($this->step->id, 'transition', 'Calling save() to persist changes...');
        $this->step->save();
        Step::log($this->step->id, 'transition', '✓ save() completed - step now RUNNING');

        Step::log($this->step->id, 'transition', 'FINAL STATE AFTER TRANSITION:');
        Step::log($this->step->id, 'transition', '  - State: Running');
        Step::log($this->step->id, 'transition', '  - Hostname: '.$this->step->hostname);
        Step::log($this->step->id, 'transition', '  - started_at: '.$this->step->started_at->format('Y-m-d H:i:s.u'));
        Step::log($this->step->id, 'transition', '  - is_throttled: false');
        Step::log($this->step->id, 'transition', '✓ PendingToRunning::handle() completed');
        Step::log($this->step->id, 'transition', '╚═══════════════════════════════════════════════════════════╝');

        return $this->step;
    }
}
