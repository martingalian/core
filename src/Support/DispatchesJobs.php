<?php

declare(strict_types=1);

namespace Martingalian\Core\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Dispatched;
use Martingalian\Core\States\Failed;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Throwable;

trait DispatchesJobs
{
    public function dispatchSingleStep(Step $step): void
    {
        Step::log($step->id, 'job', '╔═══════════════════════════════════════════════════════════╗');
        Step::log($step->id, 'job', '║         DISPATCHES-JOBS: dispatchSingleStep()            ║');
        Step::log($step->id, 'job', '╚═══════════════════════════════════════════════════════════╝');
        Step::log($step->id, 'job', 'Step details:');
        Step::log($step->id, 'job', '  - Step ID: '.$step->id);
        Step::log($step->id, 'job', '  - Class: '.($step->class ?? 'NULL'));
        Step::log($step->id, 'job', '  - Queue: '.$step->queue);
        Step::log($step->id, 'job', '  - State: '.$step->state);
        Step::log($step->id, 'job', '  - Arguments: '.json_encode($step->arguments));

        // Defense-in-depth: Refresh step and verify state before dispatching
        // This prevents duplicate dispatch if the step state changed since it was added to the collection
        $step->refresh();
        $currentState = get_class($step->state);

        if (! $step->state instanceof Dispatched) {
            Step::log($step->id, 'job', "⚠️ DISPATCH ABORTED: Step state changed to ".class_basename($currentState)." - skipping dispatch to prevent duplicate");
            Step::log($step->id, 'job', '╚═══════════════════════════════════════════════════════════╝');

            return;
        }
        Step::log($step->id, 'job', '✓ State verified: Step is in Dispatched state');

        if (empty($step->class)) {
            Step::log($step->id, 'job', '⚠️ ERROR: Step has no class defined - transitioning to Failed');
            $step->state->transitionTo(Failed::class);
            Log::error("[DispatchesJobs] Step {$step->id} has no class defined.");
            Step::log($step->id, 'job', '╚═══════════════════════════════════════════════════════════╝');

            return;
        }

        try {
            Step::log($step->id, 'job', 'Calling instantiateJobWithArguments()...');
            $job = self::instantiateJobWithArguments($step->class, $step->arguments);
            Step::log($step->id, 'job', 'Job instance created: '.get_class($job));

            Step::log($step->id, 'job', 'Assigning step to job instance...');
            $job->step = $step;
            Step::log($step->id, 'job', 'Step assigned to job');

            // Non-functional: improves observability
            $groupLabel = isset($step->group) ? " group={$step->group}" : '';

            if ($step->queue === 'sync') {
                info_if("Calling handle() for Step ID {$step->id}, class {$step->class} on queue {$step->queue}{$groupLabel}");
                Step::log($step->id, 'job', '→ SYNC QUEUE: Calling handle() directly (synchronous execution)');
                $job->handle();
                Step::log($step->id, 'job', '← handle() completed (synchronous)');
            } else {
                info_if("Calling Queue::pushOn() for Step ID {$step->id}, class {$step->class} on queue {$step->queue}{$groupLabel}");
                Step::log($step->id, 'job', '→ ASYNC QUEUE: Calling Queue::pushOn() to queue: '.$step->queue);
                Queue::pushOn($step->queue, $job);
                Step::log($step->id, 'job', '← Queue::pushOn() completed - job queued');
            }

            Step::log($step->id, 'job', '✓ dispatchSingleStep() completed successfully');
            Step::log($step->id, 'job', '╚═══════════════════════════════════════════════════════════╝');
        } catch (Throwable $e) {
            Step::log($step->id, 'job', '⚠️⚠️⚠️ EXCEPTION CAUGHT IN dispatchSingleStep() ⚠️⚠️⚠️');
            Step::log($step->id, 'job', 'Exception details:');
            Step::log($step->id, 'job', '  - Exception class: '.get_class($e));
            Step::log($step->id, 'job', '  - Exception message: '.$e->getMessage());
            Step::log($step->id, 'job', '  - Exception file: '.$e->getFile().':'.$e->getLine());

            Step::log($step->id, 'job', 'Updating step with error information...');
            $step->update(['error_message' => ExceptionParser::with($e)->friendlyMessage()]);
            $step->update(['error_stack_trace' => ExceptionParser::with($e)->stackTrace()]);
            Step::log($step->id, 'job', 'Error information saved to step');

            // Only transition to Failed if not already in a terminal state
            Step::log($step->id, 'job', 'Refreshing step to check current state...');
            $step->refresh();
            Step::log($step->id, 'job', 'Current state after refresh: '.$step->state);

            $terminalStates = Step::terminalStepStates();
            $isTerminal = collect($terminalStates)->contains(function ($state) use ($step) {
                return $step->state->equals($state);
            });

            Step::log($step->id, 'job', 'Is terminal state? '.($isTerminal ? 'YES' : 'NO'));

            if (! $isTerminal) {
                Step::log($step->id, 'job', 'Not terminal - transitioning to Failed...');
                $step->state->transitionTo(Failed::class);
                Step::log($step->id, 'job', 'Transition to Failed completed');
            } else {
                Step::log($step->id, 'job', 'Already in terminal state - NOT transitioning to Failed');
            }

            Log::error('[DispatchSingleStep] EXCEPTION: '.$e->getMessage(), [
                'step_id' => $step->id,
                'class' => $step->class,
            ]);
            Step::log($step->id, 'job', '╚═══════════════════════════════════════════════════════════╝');
        }
    }

    protected static function instantiateJobWithArguments(string $class, ?array $arguments)
    {
        try {
            $arguments = $arguments ?? [];
            $reflectionClass = new ReflectionClass($class);
            $constructor = $reflectionClass->getConstructor();

            if (is_null($constructor)) {
                return new $class;
            }

            $parameters = $constructor->getParameters();
            $resolvedArguments = [];
            $missingArguments = [];

            foreach ($parameters as $parameter) {
                $name = $parameter->getName();

                if (array_key_exists($name, $arguments)) {
                    $resolvedArguments[] = $arguments[$name];
                } elseif ($parameter->isDefaultValueAvailable()) {
                    $resolvedArguments[] = $parameter->getDefaultValue();
                } else {
                    $missingArguments[] = $name;
                }
            }

            if (! empty($missingArguments)) {
                throw new InvalidArgumentException(
                    '[DispatchesJobs] Missing required arguments: '.implode(', ', $missingArguments)." for class {$class}"
                );
            }

            return $reflectionClass->newInstanceArgs($resolvedArguments);
        } catch (ReflectionException $e) {
            throw new RuntimeException("[DispatchesJobs] Failed to instantiate job class {$class}: ".$e->getMessage(), 0, $e);
        }
    }
}
