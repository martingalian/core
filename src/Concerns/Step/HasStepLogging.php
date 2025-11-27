<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\Step;

/**
 * Provides file-based logging for Step models.
 *
 * Logs are written to categorized directories:
 * - logs/steps/transitions/{step_id}.log  - State transitions
 * - logs/steps/dispatcher/{step_id}.log   - Dispatcher events
 * - logs/steps/job/{step_id}.log          - Job execution flow
 * - logs/steps/throttling/{step_id}.log   - ALL API throttling
 * - logs/dispatcher.log                   - Global dispatcher (stepId=null)
 *
 * Available log types:
 * - transition/transitions: State transition events
 * - dispatcher: Step dispatcher events
 * - job: Job execution flow
 * - throttling: Unified API throttling log
 */
trait HasStepLogging
{
    /**
     * Maps log types to their directory names in the new structure.
     *
     * @var array<string, string>
     */
    protected static array $typeToDirectoryMap = [
        'transition' => 'transitions',
        'transitions' => 'transitions',
        'dispatcher' => 'dispatcher',
        'job' => 'job',
        'throttling' => 'throttling',

        // Backward compatibility - all throttle_* types → throttling directory
        'throttle_binance' => 'throttling',
        'throttle_bybit' => 'throttling',
        'throttle_taapi' => 'throttling',
        'throttle_coinmarketcap' => 'throttling',
        'throttle_alternativeme' => 'throttling',
    ];

    /**
     * Valid log types mapped to their config keys.
     *
     * @var array<string, string>
     */
    protected static array $logTypeConfigMap = [
        // Step lifecycle logs
        'transition' => 'martingalian.logging.step_logging_enabled',
        'transitions' => 'martingalian.logging.step_logging_enabled',
        'dispatcher' => 'martingalian.logging.step_logging_enabled',
        'job' => 'martingalian.logging.step_logging_enabled',

        // Unified throttling
        'throttling' => 'martingalian.logging.throttler_logging_enabled',

        // Backward compatibility (DEPRECATED - still work but map to 'throttling')
        'throttle_binance' => 'martingalian.logging.throttler_logging_enabled',
        'throttle_bybit' => 'martingalian.logging.throttler_logging_enabled',
        'throttle_taapi' => 'martingalian.logging.throttler_logging_enabled',
        'throttle_coinmarketcap' => 'martingalian.logging.throttler_logging_enabled',
        'throttle_alternativeme' => 'martingalian.logging.throttler_logging_enabled',
    ];

    /**
     * Static method to log a message for a step by ID.
     * This avoids database queries when you only have the step ID.
     *
     * @param  int|string|null  $stepId  The step ID (null for dispatcher-level logs)
     * @param  string  $type  The log type (see class docblock for available types)
     * @param  string  $message  The message to log
     */
    public static function log(int|string|null $stepId, string $type, string $message): void
    {
        // Validate log type
        if (! isset(self::$logTypeConfigMap[$type])) {
            return;
        }

        // Check if logging is enabled for this type
        $configKey = self::$logTypeConfigMap[$type];
        if (! config($configKey, false)) {
            return;
        }

        // Normalize type to directory structure
        $directory = self::$typeToDirectoryMap[$type] ?? $type;

        // Handle global dispatcher logs (stepId = null)
        if ($stepId === null && $directory === 'dispatcher') {
            $logsPath = storage_path('logs');
            $logFile = "{$logsPath}/dispatcher.log";
        } else {
            // Step-specific logs go to categorized directories
            $stepIdOrDispatcher = $stepId ?? 'dispatcher';
            $logsPath = storage_path("logs/steps/{$directory}");
            $logFile = "{$logsPath}/{$stepIdOrDispatcher}.log";
        }

        // Ensure the directory exists
        if (! is_dir($logsPath)) {
            mkdir($logsPath, 0755, true);
        }

        $timestamp = now()->format('Y-m-d H:i:s.u');
        $logMessage = "[{$timestamp}] {$message}".PHP_EOL;

        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get all valid log types.
     *
     * @return array<string>
     */
    public static function getValidLogTypes(): array
    {
        return array_keys(self::$logTypeConfigMap);
    }

    /**
     * Get the path to this step's log directory.
     */
    public function getLogPath(): string
    {
        return storage_path("logs/steps/{$this->id}");
    }

    /**
     * Get the contents of a specific log file for this step.
     *
     * @param  string  $type  The log type
     * @return string|null The log contents or null if file doesn't exist
     */
    public function getLogContents(string $type): ?string
    {
        $directory = self::$typeToDirectoryMap[$type] ?? $type;
        $logFile = storage_path("logs/steps/{$directory}/{$this->id}.log");

        if (! file_exists($logFile)) {
            return null;
        }

        return file_get_contents($logFile);
    }

    /**
     * Clear all logs for this step.
     */
    public function clearLogs(): void
    {
        $directories = ['transitions', 'throttling', 'dispatcher', 'job'];

        foreach ($directories as $directory) {
            $logFile = storage_path("logs/steps/{$directory}/{$this->id}.log");

            if (file_exists($logFile)) {
                unlink($logFile);
            }
        }
    }
}
