<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Martingalian\Core\Http\Controllers\Webhooks\NotificationWebhookController;

/**
 * Core Package Webhook Routes
 *
 * These routes receive POST requests from external notification gateways.
 * CSRF protection is disabled for these routes (configured in bootstrap/app.php).
 */

// Zeptomail webhook endpoint
// Receives: hard bounce, soft bounce, open events
Route::post('/webhooks/zeptomail/events', [NotificationWebhookController::class, 'zeptomail'])
    ->name('webhooks.zeptomail');

// Pushover receipt callback endpoint
// Receives: emergency notification acknowledgment
Route::post('/webhooks/pushover/receipt', [NotificationWebhookController::class, 'pushover'])
    ->name('webhooks.pushover');
