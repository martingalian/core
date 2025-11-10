<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Martingalian\Core\Http\Controllers\Api\ConnectivityTestController;
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

/**
 * Connectivity Test Routes
 *
 * Used during user registration to test API credentials from all apiable servers.
 * Tests which server IPs can connect to exchange APIs before account creation.
 */

// Start connectivity test for user-provided credentials
// Creates test steps for all apiable servers and returns block_uuid for polling
Route::post('/connectivity-test/start', [ConnectivityTestController::class, 'start'])
    ->name('connectivity-test.start');

// Get connectivity test status by block_uuid
// Returns progress and results of all server connectivity tests
Route::get('/connectivity-test/status/{blockUuid}', [ConnectivityTestController::class, 'status'])
    ->name('connectivity-test.status');
