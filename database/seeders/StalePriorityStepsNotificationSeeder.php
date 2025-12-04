<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Martingalian\Core\Enums\NotificationSeverity;
use Martingalian\Core\Models\Notification;

final class StalePriorityStepsNotificationSeeder extends Seeder
{
    /**
     * Seed the stale priority steps notification canonical.
     * This is the CRITICAL notification for when self-healing has failed.
     */
    public function run(): void
    {
        Notification::create([
            'canonical' => 'stale_priority_steps_detected',
            'title' => 'Priority Steps Still Stuck - Manual Action Required',
            'description' => 'Triggered when steps remain stuck in Dispatched state even after being promoted to the priority queue',
            'detailed_description' => 'This CRITICAL notification is sent when steps are still stuck in Dispatched state after '.
                'being automatically promoted to the priority queue with high priority. '.
                'This means the self-healing mechanism has FAILED and manual intervention is required. '.
                'Possible causes include: Horizon priority workers not running, Redis connection issues, '.
                'queue driver misconfiguration, or worker memory exhaustion. '.
                'This requires immediate attention as the system cannot auto-recover from this state.',
            'usage_reference' => 'Used in CheckStaleDataCommand - triggered when stale Dispatched steps are already in priority queue with high priority',
            'verified' => false,
            'default_severity' => NotificationSeverity::Critical,
            'cache_duration' => 60, // 1 minute
            'cache_key' => null, // Global throttling - no cache keys needed
        ]);
    }
}
