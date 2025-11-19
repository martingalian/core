# System Architecture

## Overview
Laravel 12 cryptocurrency trading automation. Multi-exchange support (Binance, Bybit), WebSocket data streaming, background job processing (Horizon), step-based job execution, and multi-channel notifications.

## Technology Stack
- **PHP**: 8.4.13, **Laravel**: v12, **Database**: MySQL, **Queue**: Redis + Horizon v5
- **Testing**: Pest v4, PHPUnit v12, **CSS**: Tailwind v4, **Bundler**: Vite
- **Exchanges**: Binance, Bybit, **Market Data**: TAAPI, CoinMarketCap, Alternative.me
- **Notifications**: Pushover, SMTP, **Quality**: Pint v1, Larastan v3, Rector v2

## Directory Structure

**IMPORTANT**: Laravel 12 has no `app/Console/Kernel.php`, no middleware directory. You can now create new migration files and seeders under `packages/martingalian/core/database/migrations/` and `packages/martingalian/core/database/seeders/`. Migration files should directly call their corresponding seeder in the up() method after creating tables.

```
app/
├── Console/Commands/        # Auto-registered
├── Enums/
├── Mail/
├── Models/                  # App-specific models
└── Support/                 # Helper classes

packages/martingalian/core/src/
├── Abstracts/               # BaseQueueableJob, BaseApiableJob, BaseApiClient, BaseWebsocketClient, BaseExceptionHandler, BaseModel
├── Models/                  # User, Account, Position, Order, Step, ApiRequestLog, Notification, NotificationLog, ThrottleLog, ThrottleRule
├── Notifications/           # AlertNotification
├── Mail/                    # AlertMail
├── Enums/                   # NotificationSeverity
├── Listeners/               # NotificationLogListener
├── Concerns/                # Traits for models (HasModelCache, ApiRequestLog/SendsNotifications, Step/HasActions, etc.)
├── States/                  # Step states (Pending, Running, Completed, Failed, etc.)
├── Support/                 # ApiClients, ExceptionHandlers, StepDispatcher, NotificationService, ModelCache, Throttler
└── database/                # migrations/, factories/, seeders/ (ALL HERE, not in main app)
```

## Database Schema

### users
- `is_active` (boolean) - controls notification delivery
- `notification_channels` (JSON) - ['mail', 'pushover'] or null (defaults to pushover)
- `pushover_key` (nullable) - for Pushover delivery

### accounts
- `user_id` (nullable) - if null, notifications go to admin
- `api_system_id` - FK to api_systems
- `api_key`, `api_secret` (encrypted)

### api_systems
- `canonical` - binance, bybit, taapi, coinmarketcap, alternativeme

### api_request_logs
- `account_id` (nullable), `api_system_id`
- `http_response_code`, `response` (JSON)
- `hostname` - server that made request
- **Observer**: Triggers `SendsNotifications::sendNotificationIfNeeded()` on save

### notifications
- `canonical` - message template identifier (e.g., 'api_access_denied')
- `user_types` (JSON) - ['user'], ['admin'], or ['admin', 'user']

### throttle_rules
- `canonical` - throttle identifier (e.g., 'server_rate_limit_exceeded')
- `throttle_seconds` - minimum time between notifications
- Database-driven throttling (deprecated `throttle_logs` table removed in favor of `notification_logs`)

See `Specs/StepDispatcher.md` for steps tables schema

## Job Architecture

### Base Classes

**BaseQueueableJob** (`packages/martingalian/core/src/Abstracts/BaseQueueableJob.php`)
- `__construct()`: ONLY attribute assignments (NO processing logic)
- `handle()`: Processing logic entry point
- Implements `ShouldQueue`

**BaseApiableJob** (extends BaseQueueableJob)
- Exception handling via BaseExceptionHandler
- Rate limit compliance
- IP ban coordination

**RULE**: Never run `php artisan steps:dispatch` manually - supervisor runs it every second

### Job Organization - Lifecycle Jobs

**Lifecycle Jobs** are orchestrator jobs that dispatch other jobs to create workflows. They are organized by the parameter type they receive in their constructor:

**Rule**: Place lifecycle jobs in `packages/martingalian/core/src/Jobs/Lifecycles/{ParameterType}/`

**Examples**:
- Job receives `apiSystemId` → `Jobs/Lifecycles/ApiSystem/`
- Job receives `accountId` → `Jobs/Lifecycles/Accounts/`
- Job receives `exchangeSymbolId` → `Jobs/Lifecycles/ExchangeSymbols/`
- Job receives `positionId` → `Jobs/Lifecycles/Positions/`
- Job receives `orderId` → `Jobs/Lifecycles/Orders/`

**Example Implementation**:
```php
// Jobs/Lifecycles/ApiSystem/DiscoverExchangeSymbolsJob.php
namespace Martingalian\Core\Jobs\Lifecycles\ApiSystem;

final class DiscoverExchangeSymbolsJob extends BaseApiableJob
{
    public ApiSystem $apiSystem;

    public function __construct(int $apiSystemId) // ← Parameter type determines folder
    {
        $this->apiSystem = ApiSystem::findOrFail($apiSystemId);
    }

    public function computeApiable()
    {
        // Dispatch child jobs using Step::create()
        Step::create([
            'class' => GetAllSymbolsFromExchangeJob::class,
            'arguments' => ['apiSystemId' => $this->apiSystem->id],
            'child_block_uuid' => $this->uuid(), // Creates parent-child relationship
        ]);
    }
}
```

**Non-Lifecycle Jobs** (standard model-specific jobs) go in `Jobs/Models/{ModelName}/`

### Queue Configuration
- **Horizon**: Manages queue workers (`/horizon` dashboard)
- **Supervisors**: default, notifications, api
- **Connections**: sync (testing), redis (production)

## Command Architecture

### Command Types
- **Cronjobs** (`app/Console/Commands/Cronjobs/`): Scheduled tasks (snapshot balances, update prices)
- **Administrative**: One-off manual commands (make:admin, exchange:sync-orders)
- **Testing** (`app/Console/Commands/Tests/`): Development/testing (test:notifications)

**Scheduling**: `routes/console.php` (Laravel 12 - no Kernel.php)

### Command Rules
1. Type hints for all parameters
2. Return exit codes (0 = success, 1+ = error)
3. Notify admin on critical failures

## Notification Flow

**RULE**: All notifications originate from `ApiRequestLog` model (via `SendsNotifications` trait). `BaseExceptionHandler` does NOT send notifications - only handles retries, rate limits, IP bans.

### Flow
1. **API Request Logged** → `ApiRequestLog` created with `http_response_code`, `response`, `hostname`
2. **Observer Triggered** → `ApiRequestLogObserver::saved()` calls `$log->sendNotificationIfNeeded()`
3. **Analyze HTTP Code** → Trait checks if `http_response_code >= 400`
4. **Load Handler** → Creates `BaseExceptionHandler::make($apiSystem->canonical)` for code analysis
5. **Route Notification** → Calls `sendUserNotification()` (if account_id) or `sendAdminNotification()` (if null)
6. **Check Error Type** → Uses `handler->isForbiddenFromLog()`, `handler->isRateLimitedFromLog()` to determine error type
7. **Build Message** → `NotificationMessageBuilder::build($canonical, $context)` creates user-friendly message
8. **Check user_types** → `Notification::findByCanonical($canonical)->user_types` determines recipients
9. **Send** → `NotificationService::sendToUser()` or `::sendToAdmin()`
10. **Deliver** → `AlertNotification` respects `user->notification_channels` (['mail', 'pushover'])

### Key Classes (All in Martingalian\Core namespace)
- **ApiRequestLog** (uses `SendsNotifications` trait) - Single source of truth (`Martingalian\Core\Models\ApiRequestLog`)
- **ApiRequestLogObserver** - Minimal trigger (8 lines) (`Martingalian\Core\Observers\ApiRequestLogObserver`)
- **BaseExceptionHandler** - HTTP code analysis (`Martingalian\Core\Abstracts\BaseExceptionHandler`)
- **NotificationMessageBuilder** - Template builder (`Martingalian\Core\Support\NotificationMessageBuilder`)
- **NotificationService** - Delivery layer (`Martingalian\Core\Support\NotificationService`)
- **Throttler** - Rate limiting service (`Martingalian\Core\Support\Throttler`)
- **AlertNotification** - Laravel notification class (`Martingalian\Core\Notifications\AlertNotification`)
- **AlertMail** - Email mailable (`Martingalian\Core\Mail\AlertMail`)
- **NotificationSeverity** - Severity enum (`Martingalian\Core\Enums\NotificationSeverity`)

### Canonical Types
- **Throttle Canonical**: `{exchange}_{error_type}` (e.g., `binance_rate_limit_exceeded`) - for throttling
- **Message Canonical**: `{error_type}` (e.g., `api_access_denied`) - for templates

See `Specs/Notifications.md` for detailed canonical list and routing rules

## WebSocket Architecture

### Flow
1. Initialize event loop (ReactPHP)
2. Establish connection (Ratchet)
3. Subscribe to streams
4. Message handler dispatches jobs
5. Keepalive pings
6. Auto-reconnect on disconnect

### Clients
- **BinanceApiClient**: `wss://stream.binance.com:9443/ws/{streams}`, keepalive every 30min
- **BybitApiClient**: `wss://stream.bybit.com/v5/public/linear`, ping every 20s

**Data Processing**: Incoming messages dispatch jobs (e.g., `ProcessTickerDataJob::dispatch($data)`)

## Configuration

**RULE**: Never use `env()` outside config files. Always use `config('key')` in application code.

### Config Files
- `config/martingalian.php`: API credentials, dispatch groups, Pushover config
- `config/horizon.php`: Queue supervisors, balance strategies
- `config/app.php`, `config/database.php`, `config/mail.php`, `config/services.php`

**Runtime config**: `config(['app.debug' => false])` (temporary)

## Security & Performance

### Security
- API credentials encrypted in database
- Laravel built-in authentication/authorization
- Rate limiting: API endpoints, notifications (30min throttle), exchange compliance
- IP ban coordination via Redis

### Performance
- **Database**: Indexes, eager loading, pessimistic locking, transactions
- **Caching**: Redis (API responses, config, routes/views in production)
- **Queues**: Priority-based, Horizon auto-scaling

## Deployment

### Requirements
PHP 8.4+, MySQL 8.0+, Redis 6.0+, Composer 2.0+, Node.js 18+

### Services (Supervisor Managed)
- **Horizon**: Queue workers
- **WebSocket**: Long-running connection
- **Scheduler**: Cron `php artisan schedule:run` every minute

### Deployment Steps
1. `git pull` → `composer install --no-dev --optimize-autoloader` → `php artisan migrate --force`
2. `php artisan optimize:clear` → `php artisan optimize` → `npm run build`
3. `php artisan horizon:terminate` → Restart WebSocket supervisor

## Monitoring
- **Logs**: `storage/logs/laravel.log` (PSR-3)
- **Horizon**: `/horizon` dashboard (job metrics, failures)
- **Alerts**: Critical errors notify admin

## Namespace Organization

### Core Package (`Martingalian\Core`)
**Package Path**: `packages/martingalian/core/src/`

All domain logic, models, notifications, and support services live in the Core package namespace. This ensures the core trading engine is portable and reusable.

**What Lives in Core**:
- Models: User, Account, Position, Order, Step, ApiRequestLog, Notification, NotificationLog, ThrottleLog, ThrottleRule
- Notifications: AlertNotification
- Mail: AlertMail
- Support: NotificationService, NotificationMessageBuilder, Throttler, StepDispatcher, ApiClients, ExceptionHandlers
- Abstracts: BaseQueueableJob, BaseApiableJob, BaseApiClient, BaseExceptionHandler
- Concerns: Traits for models (SendsNotifications, HasActions, etc.)
- States: Step states (Pending, Running, Completed, Failed)
- Enums: NotificationSeverity
- Listeners: NotificationLogListener
- Observers: ApiRequestLogObserver, StepObserver
- Database: migrations/, factories/, seeders/

### App Layer (`App\`)
**Path**: `app/`

Application-specific controllers, commands, and HTTP layer concerns remain in the App namespace.

**What Lives in App**:
- Controllers: `App\Http\Controllers\` (including NotificationWebhookController)
- Console Commands: `App\Console\Commands\`
- HTTP Middleware: Registered in `bootstrap/app.php`
- Providers: `App\Providers\` (EventServiceProvider, AppServiceProvider)
- Test Support: `App\Support\Tests\` (TestQueueableJob, etc.)

### Migration History (November 2025)
All notification-related logic was migrated from `App\` to `Martingalian\Core\` namespace to consolidate the core domain logic in the package. This migration included:
- NotificationService, NotificationMessageBuilder, Throttler → `Core\Support\`
- Notification, NotificationLog, ThrottleLog, ThrottleRule → `Core\Models\`
- NotificationSeverity → `Core\Enums\`
- AlertMail → `Core\Mail\`
- NotificationLogListener → `Core\Listeners\`

**Exception**: Controllers remain in `App\Http\Controllers\` (HTTP layer separation).

## Important Conventions

### Never Do These
1. ❌ Use `env()` outside config files
2. ❌ Put processing logic in job `__construct()`
3. ❌ Use `Model::insert()` (skips observers)
4. ❌ Use cascading deletes in migrations
5. ❌ Make business decisions (always ask first)
6. ❌ Run `steps:dispatch` (supervisor handles it)
7. ❌ Create migrations in main Laravel project (use core package)
8. ❌ Use private methods (breaks static analysis)

### Always Do These
1. ✅ Use `config()` for configuration values
2. ✅ Use `Model::create()` to trigger observers
3. ✅ Use public methods (or ArchTests will fail)
4. ✅ Use DB transactions for related operations
5. ✅ Use pessimistic locking for concurrent updates
6. ✅ Run Pint before committing
7. ✅ Write tests for new features
8. ✅ Comment code meaningfully (not what, but why)
9. ✅ Restart Horizon after changing job classes
10. ✅ Ask before making business decisions
