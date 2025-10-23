<?php

declare(strict_types=1);

namespace Martingalian\Core\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Failed;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Throwable;

trait DispatchesJobs
{
    public function dispatchSingleStep(Step $step): void
    {
        $dispatchStart = microtime(true);
        \Log::channel('dispatcher')->debug('[DispatchSingleStep] Step ID: '.$step->id.' | Class: '.class_basename($step->class).' | Queue: '.$step->queue.' | Index: '.$step->index);

        if (empty($step->class)) {
            $step->state->transitionTo(Failed::class);
            Log::error("[DispatchesJobs] Step {$step->id} has no class defined.");
            \Log::channel('dispatcher')->error('[DispatchSingleStep] FAILED: No class defined for Step '.$step->id);

            return;
        }

        try {
            $instantiateStart = microtime(true);
            $job = self::instantiateJobWithArguments($step->class, $step->arguments);
            $job->step = $step;
            $instantiateTime = round((microtime(true) - $instantiateStart) * 1000, 2);

            // Non-functional: improves observability
            $groupLabel = isset($step->group) ? " group={$step->group}" : '';

            if ($step->queue === 'sync') {
                info_if("Calling handle() for Step ID {$step->id}, class {$step->class} on queue {$step->queue}{$groupLabel}");
                \Log::channel('dispatcher')->debug('[DispatchSingleStep] Calling handle() synchronously | Instantiate: '.$instantiateTime.'ms');
                $job->handle();
            } else {
                info_if("Calling Queue::pushOn() for Step ID {$step->id}, class {$step->class} on queue {$step->queue}{$groupLabel}");
                $pushStart = microtime(true);
                Queue::pushOn($step->queue, $job);
                $pushTime = round((microtime(true) - $pushStart) * 1000, 2);
                \Log::channel('dispatcher')->debug('[DispatchSingleStep] Pushed to queue | Instantiate: '.$instantiateTime.'ms | Push: '.$pushTime.'ms');
            }

            $totalTime = round((microtime(true) - $dispatchStart) * 1000, 2);
            \Log::channel('dispatcher')->debug('[DispatchSingleStep] COMPLETE | Total: '.$totalTime.'ms');
        } catch (Throwable $e) {
            $step->update(['error_message' => ExceptionParser::with($e)->friendlyMessage()]);
            $step->update(['error_stack_trace' => ExceptionParser::with($e)->stackTrace()]);
            $step->state->transitionTo(Failed::class);
            \Log::channel('dispatcher')->error('[DispatchSingleStep] EXCEPTION: '.$e->getMessage());
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
