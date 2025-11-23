<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Martingalian\Core\Enums\NotificationSeverity;
use Martingalian\Core\Models\Notification;

final class SlowQueryNotificationSeeder extends Seeder
{
    /**
     * Seed the slow query notification canonical.
     */
    public function run(): void
    {
        Notification::create([
            'canonical' => 'slow_query_detected',
            'title' => 'Slow Database Query Detected',
            'description' => 'Triggered when a database query exceeds the configured slow_query_threshold_ms value (default: 2500ms)',
            'detailed_description' => 'This notification is sent when a database query takes longer than the threshold configured in config/martingalian.php. '.
                'The notification includes the full SQL query with binded values (ready to copy-paste into SQL editor), execution time, and connection name. '.
                'Slow queries can indicate performance issues, missing indexes, or inefficient queries that need optimization.',
            'usage_reference' => 'Used in CoreServiceProvider::registerSlowQueryListener() - triggered automatically when DB::listen() detects slow queries',
            'verified' => true,
            'default_severity' => NotificationSeverity::High,
            'cache_duration' => 300, // 5 minutes
            'cache_key' => null, // Global throttling - no cache keys needed
        ]);
    }
}
