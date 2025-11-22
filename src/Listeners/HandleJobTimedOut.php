<?php

declare(strict_types=1);

namespace Martingalian\Core\Listeners;

use Illuminate\Queue\Events\JobTimedOut;
use Martingalian\Core\Models\Step;
use Throwable;

final class HandleJobTimedOut
{
    /**
     * Handle the JobTimedOut event.
     * Updates the step's error_message when a job times out.
     */
    public function handle(JobTimedOut $event): void
    {
        try {
            // Get the job payload
            $payload = json_decode($event->job->getRawBody(), true);

            // Extract step ID from the job payload
            // The payload structure: payload.data.command contains serialized job
            if (! isset($payload['data']['command'])) {
                info('[HandleJobTimedOut] No command found in job payload');

                return;
            }

            $command = unserialize($payload['data']['command']);

            // Check if the job has a step property
            if (! isset($command->step) || ! $command->step instanceof Step) {
                info('[HandleJobTimedOut] No Step found in job command');

                return;
            }

            $step = $command->step;
            $stepId = $step->id;

            log_step($stepId, '[HandleJobTimedOut] Job timed out | Updating error_message');
            info("[HandleJobTimedOut] Step ID {$stepId} | Job class: ".get_class($command).' | Job timed out');

            // Update the step's error_message
            $timeoutSeconds = $event->job->timeout();
            $errorMessage = "Job execution timed out after {$timeoutSeconds} seconds";

            $step->update([
                'error_message' => $errorMessage,
            ]);

            log_step($stepId, '[HandleJobTimedOut] error_message updated successfully');
            info("[HandleJobTimedOut] Step ID {$stepId} | error_message updated");

            // Create application log entry if step has a relatable
            if ($step->relatable_type && $step->relatable_id) {
                log_step($stepId, '[HandleJobTimedOut] Creating application log for relatable');

                $step->appLog(
                    eventType: 'job_timeout',
                    metadata: [
                        'job_class' => get_class($command),
                        'timeout_seconds' => $timeoutSeconds,
                        'step_id' => $stepId,
                    ],
                    relatable: $step->relatable,
                    message: $errorMessage
                );

                log_step($stepId, '[HandleJobTimedOut] Application log created');
                info("[HandleJobTimedOut] Step ID {$stepId} | Application log created for relatable");
            } else {
                log_step($stepId, '[HandleJobTimedOut] No relatable, skipping application log');
            }
        } catch (Throwable $e) {
            // Fail silently, don't break the queue worker
            info('[HandleJobTimedOut] ERROR: '.$e->getMessage().' | File: '.$e->getFile().':'.$e->getLine());
        }
    }
}
