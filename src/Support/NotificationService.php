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
 *
 * Usage:
 *   // Admin notification
 *   NotificationService::send(
 *       user: Martingalian::admin(),
 *       canonical: 'server_rate_limit_exceeded',
 *       referenceData: ['exchange' => 'binance']
 *   );
 *
 *   // User notification
 *   NotificationService::send(
 *       user: $user,
 *       canonical: 'price_alert',
 *       referenceData: ['symbol' => 'BTC']
 *   );
 */
final class NotificationService
{
    /**
     * Send a notification to a specific user.
     *
     * @param  User  $user  The user to send to (use Martingalian::admin() for admin notifications)
     * @param  string  $canonical  Notification canonical identifier (e.g., 'server_rate_limit_exceeded')
     * @param  array<string, mixed>  $referenceData  Reference data for template interpolation (e.g., ['exchange' => 'binance'])
     * @param  object|null  $relatable  Optional relatable model for audit trail
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
        return self::sendToSpecificUser(
            user: $user,
            canonical: $canonical,
            referenceData: $referenceData,
            relatable: $relatable,
            duration: $duration,
            cacheKey: $cacheKey
        );
    }

    /**
     * Send notification to a specific user (internal use only).
     * Handles throttling, message building, and actual notification dispatch.
     *
     * @param  User  $user  The user to notify (real User or virtual admin via Martingalian::admin())
     * @param  string  $canonical  Notification canonical identifier
     * @param  array<string, mixed>  $referenceData  Reference data for template interpolation
     * @param  object|null  $relatable  Optional relatable model for audit trail
     * @param  int|null  $duration  Throttle duration in seconds
     * @param  string|null  $cacheKey  Optional cache key for throttling
     * @return bool True if notification was sent, false otherwise
     */
    private static function sendToSpecificUser(
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
}
