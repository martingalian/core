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
        if (empty($step->class)) {
            $step->state->transitionTo(Failed::class);
            Log::error("[DispatchesJobs] Step {$step->id} has no class defined.");

            return;
        }

        try {
            $job = self::instantiateJobWithArguments($step->class, $step->arguments);
            $job->step = $step;

            // Non-functional: improves observability
            $groupLabel = isset($step->group) ? " group={$step->group}" : '';

            if ($step->queue === 'sync') {
                info_if("Calling handle() for Step ID {$step->id}, class {$step->class} on queue {$step->queue}{$groupLabel}");
                $job->handle();
            } else {
                info_if("Calling Queue::pushOn() for Step ID {$step->id}, class {$step->class} on queue {$step->queue}{$groupLabel}");
                Queue::pushOn($step->queue, $job);
            }
        } catch (Throwable $e) {
            $step->update(['error_message' => ExceptionParser::with($e)->friendlyMessage()]);
            $step->update(['error_stack_trace' => ExceptionParser::with($e)->stackTrace()]);
            $step->state->transitionTo(Failed::class);
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
