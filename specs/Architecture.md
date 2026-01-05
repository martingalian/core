# System Architecture

## Overview

Laravel 12 cryptocurrency trading automation platform. Multi-exchange support with background job processing, step-based workflow execution, and multi-channel notifications.

---

## Technology Stack

| Category | Technology |
|----------|------------|
| Backend | PHP 8.4, Laravel 12, MySQL |
| Queue | Redis, Laravel Horizon |
| Testing | Pest v4, PHPUnit v12 |
| Static Analysis | Larastan v3 |
| Frontend | Tailwind CSS v4, Alpine.js, Vite |
| Exchanges | Binance, Bybit, Kraken, KuCoin, Bitget |
| Market Data | TAAPI, CoinMarketCap, Alternative.me |
| Notifications | Pushover, SMTP (Zeptomail) |

---

## Package Structure

The system is organized into two main packages:

### Core Package (`Martingalian\Core`)
Contains all domain logic, models, and business rules:
- **Models**: User, Account, Position, Order, Step, ExchangeSymbol, ApiRequestLog, Notification
- **Jobs**: BaseQueueableJob, BaseApiableJob, all business logic jobs
- **Support**: API clients, exception handlers, StepDispatcher, NotificationService
- **Database**: All migrations, factories, and seeders

### Application Layer (`App\`)
Contains HTTP layer and console commands:
- **Controllers**: Web and API controllers
- **Commands**: Cronjobs, administrative commands, test commands
- **HTTP**: Middleware, form requests

---

## Job Architecture

### Base Classes

| Class | Purpose |
|-------|---------|
| `BaseQueueableJob` | Foundation for all queued jobs. Provides lifecycle hooks, exception handling, retry logic |
| `BaseApiableJob` | Extends BaseQueueableJob. Adds API-specific handling: rate limiting, pre-flight checks, response caching |

### Job Organization

Jobs are organized by their primary parameter:

| Parameter Type | Location | Example |
|----------------|----------|---------|
| `apiSystemId` | `Jobs/Lifecycles/ApiSystem/` | DiscoverExchangeSymbolsJob |
| `accountId` | `Jobs/Lifecycles/Account/` | SyncAccountBalanceJob |
| `accountId` | `Jobs/Models/Account/` | TestAccountServerConnectivityJob |
| `exchangeSymbolId` | `Jobs/Models/ExchangeSymbol/` | FetchKlinesJob |
| `positionId` | `Jobs/Models/Position/` | ClosePositionJob |

### Lifecycle Jobs (Special Category)

Jobs in `Jobs/Lifecycles/` are **orchestrators**, not queued jobs:
- Extend `BaseLifecycle` abstract class (NOT `BaseQueueableJob`)
- Do NOT implement `ShouldQueue` interface
- Create Step records for atomic jobs to be dispatched
- Return next available index for chaining

```php
// Lifecycle orchestrator pattern
class QueryAccountPositionsJob extends BaseLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => AtomicQueryAccountPositionsJob::class,
            'arguments' => ['accountId' => $this->account->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
        ]);
        return $startIndex + 1;
    }
}
```

### Critical Rules

1. **Constructor**: Only attribute assignments, NO processing logic
2. **Observers**: Always use `Model::create()` not `Model::insert()` to trigger observers
3. **Horizon**: Restart after changing job classes
4. **Dispatching**: Never run `steps:dispatch` manually - supervisor handles it

---

## Step Dispatcher

A state machine-based workflow engine for reliable job execution.

### Core Concepts

- **Step**: A database record representing a job to be executed
- **Parent-Child**: Steps can have dependencies forming execution chains
- **States**: Pending → Dispatched → Running → Completed/Failed/Stopped
- **Dispatch Groups**: Load balancing across servers

### State Transitions

```
Pending → Dispatched → Running → Completed
                              → Failed (→ Pending if retry)
                              → Stopped
        → Cancelled
        → Skipped
```

### Business Rules

1. Parent cannot complete until ALL children are concluded
2. Parent failure cascades to all children
3. Parent skip/cancel cascades to all children
4. If ALL children fail, parent transitions to Failed
5. Steps only dispatch after `dispatch_after` timestamp

See `StepDispatcher.md` for complete documentation.

---

## Notification Flow

All notifications originate from model observers or cronjob commands:

1. **Trigger**: Observer detects event (API error, status change)
2. **Throttle Check**: Database or cache-based throttling prevents spam
3. **Message Build**: Template builder creates user-friendly content
4. **Dispatch**: AlertNotification sent via user's preferred channels
5. **Log**: NotificationLog created for audit trail

### Key Principles

- ApiRequestLogObserver handles API error notifications
- HeartbeatObserver handles WebSocket status notifications
- ForbiddenHostnameObserver handles IP ban notifications
- ExceptionHandlers classify errors but do NOT send notifications

See `NotificationSystem-Current.md` for complete documentation.

---

## Configuration

### Critical Rules

1. **Never use `env()` outside config files** - Always use `config('key')`
2. **Restart supervisors after config changes** - Long-running processes cache config
3. **Use transactions and pessimistic locking** for database operations

### Key Config Files

| File | Purpose |
|------|---------|
| `config/martingalian.php` | API credentials, dispatch groups, Pushover config |
| `config/horizon.php` | Queue supervisors, balance strategies |
| `routes/console.php` | Scheduled commands (Laravel 12 - no Kernel.php) |

---

## Database Conventions

### Model Rules

1. Use `Model::create()` to trigger observers
2. Never use cascading deletes in migrations
3. All migrations go in core package, not main Laravel project
4. Migrations call their seeders in the `up()` method

### Query Rules

1. Use Eloquent relationships over manual joins
2. Use eager loading to prevent N+1 queries
3. Use `Model::query()` instead of `DB::` facade
4. Wrap multi-step operations in transactions

---

## Security

- API credentials encrypted in database
- Rate limiting on all API calls
- IP ban coordination via Redis cache
- Notification throttling prevents spam

---

## Deployment

### Services (Supervisor Managed)

| Service | Purpose |
|---------|---------|
| `horizon` | Queue workers |
| `schedule-work` | Cron scheduler |

### Deployment Steps

1. Disable circuit breaker (stop new dispatches)
2. Wait for active jobs to complete
3. Deploy code: `git pull`, `composer install`, `migrate`
4. Clear and rebuild caches
5. Restart Horizon
6. Re-enable circuit breaker

---

## Important Conventions

### Never Do

- Use `env()` outside config files
- Put processing logic in job constructors
- Use `Model::insert()` (skips observers)
- Use cascading deletes
- Make business decisions without asking
- Run `steps:dispatch` manually
- Use private methods (breaks static analysis)

### Always Do

- Use `config()` for configuration values
- Use `Model::create()` to trigger observers
- Use public methods only
- Use DB transactions for related operations
- Use pessimistic locking for concurrent updates
- Run Pint before committing
- Write tests for new features
- Restart Horizon after changing job classes
