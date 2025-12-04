<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Martingalian\Core\Enums\NotificationSeverity;
use Martingalian\Core\Models\Notification;

final class StaleDispatchedStepsNotificationSeeder extends Seeder
{
    /**
     * Seed the stale dispatched steps notification canonical.
     */
    public function run(): void
    {
        Notification::create([
            'canonical' => 'stale_dispatched_steps_detected',
            'title' => 'Stale Dispatched Steps Detected',
            'description' => 'Triggered when steps remain in Dispatched state for more than 5 minutes without starting processing',
            'detailed_description' => 'This notification is sent when steps are stuck in Dispatched state for over 5 minutes. '.
                'Steps in Dispatched state should normally transition to Running state within seconds. '.
                'Stale dispatched steps indicate that Horizon workers are not picking up jobs, which can be caused by: '.
                'Horizon workers not running, Redis connection issues, queue driver misconfiguration, circuit breaker enabled, or worker memory exhaustion. '.
                'The notification includes the total count of stuck steps and details of the oldest stuck step (ID, canonical, group, index, parameters, minutes stuck).',
            'usage_reference' => 'Used in CheckStaleDataCommand - triggered automatically every 10 minutes when stale Dispatched steps are detected',
            'verified' => false,
            'default_severity' => NotificationSeverity::High,
            'cache_duration' => 60, // 1 minute
            'cache_key' => null, // Global throttling - no cache keys needed
        ]);
    }
}
