# Testing Strategy

## Overview

Comprehensive testing using **Pest v4** with feature tests, unit tests, integration tests, and browser tests. The project enforces quality through multiple layers: Pest tests, PHPStan (level max), Pint, Rector, and Prettier.

---

## Test Structure

```
tests/
├── Feature/          # Feature tests (majority)
│   ├── Console/Commands/
│   ├── Notifications/
│   └── Support/
├── Unit/             # Unit tests (isolated logic)
│   ├── StepDispatcher/
│   └── Support/
├── Integration/      # Integration tests (real rendering)
│   └── Mail/
├── Browser/          # Browser tests (Pest v4)
└── Pest.php          # Configuration
```

---

## Test Types

### Feature Tests
- Test complete features end-to-end
- Use `RefreshDatabase` trait
- Test database interactions and HTTP endpoints
- Test command execution

### Unit Tests
- Test isolated logic
- No database interactions (usually)
- Mock dependencies
- Test pure functions and calculations

### Integration Tests
- Test real system interactions
- Use **log mail driver** to validate real email rendering
- Catch bugs that `Mail::fake()` misses
- Test complete flow (notification → email → HTML)

### Browser Tests (Pest v4)
- Test real browser interactions
- Use Chrome, Firefox, Safari
- Test JavaScript functionality
- Take screenshots
- Test responsive design

---

## Test Configuration

### Environment Settings

| Variable | Value |
|----------|-------|
| `APP_ENV` | testing |
| `DB_CONNECTION` | testing |
| `MAIL_MAILER` | array |
| `QUEUE_CONNECTION` | sync |

### Pest.php Configuration

| Scope | Configuration |
|-------|---------------|
| Feature/Browser | RefreshDatabase, freezeTime, Http::preventStrayRequests |
| Unit | No database, freezeTime, Http::preventStrayRequests |
| Integration | IntegrationTestCase, RefreshDatabase, real mail driver |

---

## Quality Tools

| Tool | Purpose | Level |
|------|---------|-------|
| PHPStan | Static analysis | max |
| Pint | Code style | Laravel preset + strict rules |
| Rector | Code modernization | PHP 8.4 |
| Prettier | Frontend formatting | resources/ |

---

## Running Tests

### Composer Scripts

| Command | Purpose |
|---------|---------|
| `composer test` | Full suite (all tests + analysis + linting) |
| `composer test:unit` | Unit + Feature tests in parallel |
| `composer test:integration` | Integration tests (sequential) |
| `composer test:types` | PHPStan static analysis |
| `composer test:lint` | Verify code style |
| `composer lint` | Auto-fix code style |
| `composer test:coverage` | Generate coverage report (min 70%) |
| `composer test:type-coverage` | Type coverage (min 100%) |

### Direct Commands

| Command | Purpose |
|---------|---------|
| `php artisan test --testsuite=Feature` | Specific suite |
| `php artisan test tests/Feature/File.php` | Specific file |
| `php artisan test --filter="test name"` | Specific test |
| `php artisan test --parallel` | Parallel execution |

---

## Integration Test Helpers

### IntegrationTestCase Methods

| Method | Purpose |
|--------|---------|
| `assertEmailWasSent()` | Verify email sent |
| `assertNoEmailWasSent()` | Verify no email |
| `assertLastEmailContains(text)` | Check content |
| `assertLastEmailHasValidHtml()` | Validate HTML |
| `getLastEmailHtml()` | Get email HTML |
| `clearEmailLog()` | Clear log |

### Integration Test Files

| Test | Coverage |
|------|----------|
| AlertNotificationEmailTest | 17 tests - email structure, severity, XSS |
| ApiRequestLogObserverNotificationTest | 9 tests - API error notifications |
| BaseQueueableJobExceptionNotificationTest | 8 tests - job exception emails |
| MonitorDataCoherencyNotificationTest | 8 tests - stale price notifications |

---

## Testing Patterns

### Using Factories

Always use model factories for test data. Check factory states before manual setup.

### Using Datasets

For testing multiple scenarios (validation rules, etc.):
- Define scenarios with expected results
- Run same test across all scenarios

### Mocking External Services

| Service | Method |
|---------|--------|
| HTTP | `Http::fake([...])` |
| Notifications | `Notification::fake()` |
| Mail | `Mail::fake()` |

---

## Integration Test Best Practices

### Helper Functions CANNOT Access test() Context

Helper functions must create their own dependencies - they cannot access `test()->user` or similar.

### Quote vs Symbol Models

| Model | Key Field | Purpose |
|-------|-----------|---------|
| Quote | `canonical` | Quote currency (USDT) |
| Symbol | `token` | Base asset (BTC) |
| ExchangeSymbol | Both | Links symbol + quote |

### Use firstOrCreate() to Avoid Duplicates

Always use `firstOrCreate()` in test helpers to prevent duplicate constraint violations.

### Required Model Fields

| Model | Required Fields |
|-------|----------------|
| Account | `trade_configuration_id`, `api_system_id`, `user_id` |
| ExchangeSymbol | `symbol_id`, `quote_id`, `api_system_id` |

### Decimal Precision

Database stores financial fields with 8 decimal places. Assertions must match: `'51000.00000000'` not `'51000'`.

---

## HTTP Mocking Patterns

### URL Pattern Requirements

BaseApiClient generates double slashes in testing mode:

| Pattern | Matches |
|---------|---------|
| `'*//fapi/v1/order*'` | `https://fapi.binance.com//fapi/v1/order` |
| `'*//fapi/*/positionRisk*'` | Both v2 and v3 versions |

### Symbol Format in Mocks

Always use **raw exchange format** in HTTP mocks:

| Correct | Incorrect |
|---------|-----------|
| `'BTCUSDT'` | `'BTC/USDT'` |

ApiDataMapper transforms raw to internal format.

### Complete Response Fields

Mock responses must include ALL fields the application expects (orderId, symbol, status, type, side, etc.).

---

## Data Isolation Pattern

### The Problem

Tests relying on global counts or `Model::first()` are fragile with stale data.

### The Solution

Always query by specific identifiers:
- Filter by `exchange_symbol_id`, `timestamp`, `timeframe`
- Use unique tokens per test (`TEST_SCENARIO_A`)
- Verify state before AND after operations

### Golden Rule

> "If there's stale data in the test database, will this assertion still correctly identify bugs?"

If no, refactor to use specific identifiers.

---

## Namespace Consistency

Always use `Martingalian\Core\Models` namespace for Core models:

| Correct | Incorrect |
|---------|-----------|
| `Martingalian\Core\Models\ExchangeSymbol` | `App\Models\ExchangeSymbol` |

---

## Best Practices

| Practice | Reason |
|----------|--------|
| Descriptive test names | `it('sends high priority email for critical notifications')` |
| Arrange-Act-Assert | Clear structure |
| Test one thing | Single responsibility |
| Use factories | Consistent test data |
| Use RefreshDatabase | Clean state |
| Test happy + unhappy paths | Complete coverage |
| Avoid test interdependence | Independent execution |
| Mock external services | No real API calls |
| Test edge cases | Empty, null, large, special chars |
| Use specific assertions | `assertForbidden()` not `assertStatus(403)` |

---

## Workflow

### During Development

1. Run filtered tests on current work
2. Auto-fix code style with `composer lint`
3. Run unit tests before committing

### Before Pull Request

1. Run full test suite: `composer test`
2. Verify all checks pass

### On Failure

| Issue | Solution |
|-------|----------|
| Tests pass, production fails | Use integration tests with real rendering |
| Slow tests | Use RefreshDatabase, mock services, parallel |
| Flaky tests | Avoid time-dependent logic, use freezeTime |
| Database pollution | Use RefreshDatabase |

---

## Coverage Goals

| Area | Target |
|------|--------|
| Overall | 80%+ |
| Critical paths | 100% (notifications, payments, trading) |
| Edge cases | All error scenarios |
| Integration | All user-facing features |

---

## Related Systems

- **Pest v4**: Test framework with browser testing
- **PHPStan**: Static analysis
- **Pint**: Code style
- **IntegrationTestCase**: Real email rendering tests
