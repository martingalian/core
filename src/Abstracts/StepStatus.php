<?php

namespace Martingalian\Core\Abstracts;

use Martingalian\Core\States\Cancelled;
use Martingalian\Core\States\Completed;
use Martingalian\Core\States\Dispatched;
use Martingalian\Core\States\Failed;
use Martingalian\Core\States\NotRunnable;
use Martingalian\Core\States\Pending;
use Martingalian\Core\States\Running;
use Martingalian\Core\States\Skipped;
use Martingalian\Core\States\Stopped;
use Martingalian\Core\Transitions\DispatchedToCancelled;
use Martingalian\Core\Transitions\DispatchedToFailed;
use Martingalian\Core\Transitions\DispatchedToRunning;
use Martingalian\Core\Transitions\NotRunnableToPending;
use Martingalian\Core\Transitions\PendingToCancelled;
use Martingalian\Core\Transitions\PendingToDispatched;
use Martingalian\Core\Transitions\PendingToFailed;
use Martingalian\Core\Transitions\PendingToSkipped;
use Martingalian\Core\Transitions\RunningToCompleted;
use Martingalian\Core\Transitions\RunningToFailed;
use Martingalian\Core\Transitions\RunningToPending;
use Martingalian\Core\Transitions\RunningToRunning;
use Martingalian\Core\Transitions\RunningToSkipped;
use Martingalian\Core\Transitions\RunningToStopped;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class StepStatus extends State
{
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Pending::class)
            ->allowTransition(Pending::class, Dispatched::class, PendingToDispatched::class)
            ->allowTransition(Pending::class, Cancelled::class, PendingToCancelled::class)
            ->allowTransition(Pending::class, Failed::class, PendingToFailed::class)
            ->allowTransition(Pending::class, Skipped::class, PendingToSkipped::class)

            ->allowTransition(Dispatched::class, Running::class, DispatchedToRunning::class)
            ->allowTransition(Dispatched::class, Cancelled::class, DispatchedToCancelled::class)
            ->allowTransition(Dispatched::class, Failed::class, DispatchedToFailed::class)

            ->allowTransition(Running::class, Completed::class, RunningToCompleted::class)
            ->allowTransition(Running::class, Stopped::class, RunningToStopped::class)
            ->allowTransition(Running::class, Failed::class, RunningToFailed::class)
            ->allowTransition(Running::class, Skipped::class, RunningToSkipped::class)
            ->allowTransition(Running::class, Pending::class, RunningToPending::class)

            ->allowTransition(Running::class, Running::class, RunningToRunning::class)

            ->allowTransition(NotRunnable::class, Pending::class, NotRunnableToPending::class)

            ->registerState([
                Pending::class,
                Dispatched::class,
                Running::class,
                Completed::class,
                Failed::class,
                Cancelled::class,
                NotRunnable::class,
                Skipped::class,
                Stopped::class]);
    }
}
