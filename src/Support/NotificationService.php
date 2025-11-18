<?php

declare(strict_types=1);

namespace Martingalian\Core\Support;

use Illuminate\Support\Facades\Cache;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Notification;
use Martingalian\Core\Models\NotificationLog;
use Martingalian\Core\Models\User;
use Martingalian\Core\Notifications\AlertNotification;

/**
 * NotificationService
 *
 * Unified notification service that leverages Laravel's notification system.
 * Works with both real User models and virtual admin users (Martingalian::admin()).
 *
 * Usage:
 *   NotificationService::send(
 *       user: $user,  // Real or virtual user
 *       message: 'BTC price reached $50,000',
 *       title: 'Price Alert'
 *   );
 *
 *   // Admin notifications using virtual user:
 *   NotificationService::send(
 *       user: Martingalian::admin(),
 *       message: 'System error detected',
 *       title: 'System Alert',
 *       relatable: $apiSystem
 *   );
 */
final class NotificationService
{
    /**
     * Send a notification to a user (real or virtual admin user).
     *
     * This unified method handles both regular user notifications and admin notifications
     * through Laravel's standard notification system. The virtual admin user (Martingalian::admin())
     * works seamlessly with this approach.
     *
     * @param  User  $user  The user to notify (real User or virtual admin via Martingalian::admin())
     * @param  string  $canonical  Notification canonical identifier (e.g., 'ip_not_whitelisted', 'api_rate_limit_exceeded')
     * @param  array<string, mixed>  $referenceData  Reference data for template interpolation (e.g., ['exchange' => 'binance', 'ip' => '127.0.0.1'])
     * @param  object|null  $relatable  Optional relatable model (ApiSystem, Step, Account) for audit trail
     * @param  int|null  $duration  Throttle duration in seconds (null = use default from notifications table, 0 = no throttle, >0 = custom throttle window)
     * @param  string|null  $cacheKey  Optional literal cache key for cache-based throttling (e.g., 'my_custom_key'). If null, uses database-based throttling via notification_logs.
     * @return bool True if notification was sent, false otherwise
     */
    public static function send(
        User $user,
        string $canonical,
        array $referenceData = [],
        ?object $relatable = null,
        ?int $duration = null,
        ?string $cacheKey = null
    ): bool {
        // Determine throttle duration:
        // - null: use default from notifications table
        // - 0: no throttling (send immediately)
        // - >0: use custom duration
        $throttleDuration = $duration;

        if ($duration === null) {
            // Look up default throttle duration from notifications table
            $notification = Notification::where('canonical', $canonical)->first();
            $throttleDuration = $notification?->default_throttle_duration;
        }

        // Throttle check: only if throttleDuration is set and > 0
        if ($throttleDuration !== null && $throttleDuration > 0) {
            if ($cacheKey) {
                // Cache-based throttling - prefix cache key with canonical
                $prefixedCacheKey = "{$canonical}_{$cacheKey}";

                if (Cache::has($prefixedCacheKey)) {
                    // Still within throttle window - skip sending
                    return false;
                }
            } else {
                // Database-based throttling (default fallback)
                // Use $relatable if provided, otherwise use $user as the throttle relatable
                $throttleRelatable = $relatable ?? $user;

                $isThrottled = NotificationLog::query()
                    ->where('canonical', $canonical)
                    ->where('relatable_type', get_class($throttleRelatable))
                    ->where('relatable_id', $throttleRelatable->id)
                    ->where('created_at', '>', now()->subSeconds($throttleDuration))
                    ->exists();

                if ($isThrottled) {
                    // Still within throttle window - skip sending
                    return false;
                }
            }
        }

        // Build notification message from canonical template
        $messageData = NotificationMessageBuilder::build($canonical, $referenceData, $user);

        if (! $messageData) {
            return false;
        }

        // Add relatable model to user for NotificationLogListener to extract
        if ($relatable) {
            $user->relatable = $relatable;
        }

        // Build additional parameters with action URL if provided
        $additionalParameters = [];
        if ($messageData['actionUrl']) {
            $additionalParameters['url'] = $messageData['actionUrl'];
            $additionalParameters['url_title'] = $messageData['actionLabel'] ?? 'View Details';
        }

        // Send notification using Laravel's notification system
        $user->notify(
            new AlertNotification(
                message: $messageData['emailMessage'],
                title: $messageData['title'],
                canonical: $canonical,
                severity: $messageData['severity'],
                pushoverMessage: $messageData['pushoverMessage'],
                additionalParameters: $additionalParameters
            )
        );

        // Set cache throttle after successful send (cache-based throttling only)
        if ($cacheKey && $throttleDuration) {
            $prefixedCacheKey = "{$canonical}_{$cacheKey}";
            Cache::put($prefixedCacheKey, true, $throttleDuration);
        }

        return true;
    }

    /**
     * Send a notification to admin using a canonical notification template.
     * Convenience wrapper for sending to the admin user.
     *
     * @param  string  $canonical  Notification canonical identifier (e.g., 'api_rate_limit_exceeded')
     * @param  array<string, mixed>  $referenceData  Reference data for template interpolation (e.g., ['exchange' => 'binance'])
     * @param  object|null  $relatable  Optional relatable model for audit trail
     * @param  int|null  $duration  Throttle duration in seconds (null = use default from notifications table, 0 = no throttle, >0 = custom throttle window)
     * @param  string|null  $cacheKey  Optional literal cache key for cache-based throttling (e.g., 'my_custom_key'). If null, uses database-based throttling via notification_logs.
     * @return bool True if notification was sent, false otherwise
     */
    public static function sendToAdminByCanonical(
        string $canonical,
        array $referenceData = [],
        ?object $relatable = null,
        ?int $duration = null,
        ?string $cacheKey = null
    ): bool {
        // Send to admin using virtual user
        return self::send(
            user: Martingalian::admin(),
            canonical: $canonical,
            referenceData: $referenceData,
            relatable: $relatable,
            duration: $duration,
            cacheKey: $cacheKey
        );
    }
}
