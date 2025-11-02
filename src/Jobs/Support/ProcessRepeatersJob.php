<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Martingalian\Core\Models\Repeater;

final class ProcessRepeatersJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct(
        public ?string $queueName = null
    ) {
        if ($queueName) {
            $this->onQueue($queueName);
        }
    }

    public function handle(): void
    {
        $query = Repeater::where('next_run_at', '<=', now());

        // If specific queue name provided, filter by it
        if ($this->queueName) {
            $query->where('queue', $this->queueName);
        }

        $repeaters = $query->get();

        foreach ($repeaters as $repeater) {
            $this->processRepeater($repeater);
        }
    }

    public function processRepeater(Repeater $repeater): void
    {
        $repeater->update([
            'last_run_at' => now(),
        ]);

        try {
            // Instantiate the repeater class with parameters
            $instance = $this->instantiateRepeater($repeater);

            // Invoke the repeater
            $success = $instance();

            $repeater->increment('attempts');
            $repeater->refresh();

            if ($success) {
                // Success path
                $instance->passed();
                $repeater->delete();

            } elseif ($repeater->attempts >= $repeater->max_attempts) {
                // Max attempts reached
                $instance->maxAttemptsReached();
                $repeater->delete();

            } else {
                // Failed, schedule next retry
                $instance->failed();
                $repeater->update([
                    'next_run_at' => $instance->calculateNextRetryAt(),
                ]);
            }

        } catch (\Throwable $e) {
            // Log error and delete repeater on unexpected exception
            $repeater->update([
                'last_error' => $e->getMessage(),
            ]);
            $repeater->delete();
        }
    }

    public function instantiateRepeater(Repeater $repeater): \Martingalian\Core\Abstracts\BaseRepeater
    {
        $class = $repeater->class;
        $parameters = $repeater->parameters ?? [];

        // Resolve parameters (e.g., convert account_id to Account instance)
        $resolved = $this->resolveParameters($parameters);

        // Instantiate the repeater with resolved parameters
        $instance = new $class(...$resolved);

        // Set the repeater instance reference
        $instance->setRepeater($repeater);

        return $instance;
    }

    public function resolveParameters(array $parameters): array
    {
        $resolved = [];

        foreach ($parameters as $key => $value) {
            // If parameter is account_id, resolve to Account model
            if ($key === 'account_id') {
                $resolved[] = \Martingalian\Core\Models\Account::find($value);
            }
            // If parameter is symbol_id, resolve to Symbol model
            elseif ($key === 'symbol_id') {
                $resolved[] = \Martingalian\Core\Models\Symbol::find($value);
            }
            // If parameter is server_id, resolve to Server model
            elseif ($key === 'server_id') {
                $resolved[] = \Martingalian\Core\Models\Server::find($value);
            }
            // Otherwise pass raw value
            else {
                $resolved[] = $value;
            }
        }

        return $resolved;
    }
}
